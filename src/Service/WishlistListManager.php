<?php

declare(strict_types=1);

namespace Summit\Wishlist\Service;

use Summit\Wishlist\Catalog\ProductCatalogInterface;
use Summit\Wishlist\Owner\WishlistOwner;
use Summit\Wishlist\Repository\WishlistRepositoryInterface;

/**
 * Stage 2: several named lists per owner (guest or customer).
 *
 * Every mutation runs under the owner lock (WishlistRepositoryInterface::withOwnerLock) and
 * re-reads the owner's lists inside it, so unique names, the list cap, the single default
 * and membership revisions hold under concurrent requests. Read methods never create data.
 * Foreign, unknown and deleted list IDs all answer `not_found`, never revealing names or counts.
 */
class WishlistListManager
{
    /** @var WishlistRepositoryInterface */
    private $repository;
    /** @var WishlistAccessPolicy */
    private $accessPolicy;
    /** @var ProductCatalogInterface */
    private $catalog;
    /** @var WishlistNameValidator */
    private $validator;

    public function __construct(
        WishlistRepositoryInterface $repository,
        WishlistAccessPolicy $accessPolicy,
        ProductCatalogInterface $catalog,
        WishlistNameValidator $validator
    ) {
        $this->repository = $repository;
        $this->accessPolicy = $accessPolicy;
        $this->catalog = $catalog;
        $this->validator = $validator;
    }

    public function validator(): WishlistNameValidator
    {
        return $this->validator;
    }

    /**
     * @return array[] default first, then oldest first; each with `item_count`
     */
    public function getLists(WishlistOwner $owner): array
    {
        return $this->repository->findAllByOwner($owner->getShopId(), $owner->getOwnerKey());
    }

    /**
     * The list when the owner may view it, otherwise null (indistinguishable from "doesn't exist").
     */
    public function findList(WishlistOwner $owner, int $wishlistId): ?array
    {
        if ($wishlistId <= 0) {
            return null;
        }
        $list = $this->repository->find($wishlistId);

        return $list !== null && $this->accessPolicy->canView($owner, $list) ? $list : null;
    }

    /**
     * @return array{code: string, id?: int, name: string} code: created or a name/limit error
     */
    public function create(WishlistOwner $owner, string $rawName): array
    {
        $name = $this->validator->normalize($rawName);

        return $this->repository->withOwnerLock($owner->getShopId(), $owner->getOwnerKey(), function () use ($owner, $name) {
            $lists = $this->getLists($owner);
            if (count($lists) >= WishlistNameValidator::MAX_LISTS) {
                return ['code' => 'too_many_lists', 'name' => $name];
            }
            $error = $this->validator->validate($name, $lists);
            if ($error !== null) {
                return ['code' => $error, 'name' => $name];
            }
            // The owner's first list is the default ("first created is the default").
            $id = $this->repository->createList($owner->getShopId(), $owner->getOwnerKey(), $owner->getCustomerId(), $name, !$lists);

            return ['code' => 'created', 'id' => $id, 'name' => $name];
        });
    }

    /**
     * @return array{code: string, name: string} code: renamed, not_found or a name error
     */
    public function rename(WishlistOwner $owner, int $wishlistId, string $rawName): array
    {
        $name = $this->validator->normalize($rawName);
        if ($this->findList($owner, $wishlistId) === null) {
            return ['code' => 'not_found', 'name' => $name];
        }

        return $this->repository->withOwnerLock($owner->getShopId(), $owner->getOwnerKey(), function () use ($owner, $wishlistId, $name) {
            $lists = $this->getLists($owner);
            if (!$this->contains($lists, $wishlistId)) {
                return ['code' => 'not_found', 'name' => $name];
            }
            $error = $this->validator->validate($name, $lists, $wishlistId);
            if ($error !== null) {
                return ['code' => $error, 'name' => $name];
            }
            $this->repository->renameList($wishlistId, $name);

            return ['code' => 'renamed', 'name' => $name];
        });
    }

    /**
     * @return string default_set, not_found
     */
    public function setDefault(WishlistOwner $owner, int $wishlistId): string
    {
        if ($this->findList($owner, $wishlistId) === null) {
            return 'not_found';
        }

        return $this->repository->withOwnerLock($owner->getShopId(), $owner->getOwnerKey(), function () use ($owner, $wishlistId) {
            if (!$this->contains($this->getLists($owner), $wishlistId)) {
                return 'not_found';
            }
            $this->repository->setDefault($owner->getShopId(), $owner->getOwnerKey(), $wishlistId);

            return 'default_set';
        });
    }

    /**
     * Deletes one list and its entries. Other lists and catalogue products are untouched.
     * If it was the default, the oldest remaining list becomes the default.
     *
     * @return array{code: string, name: string} code: deleted, not_found
     */
    public function delete(WishlistOwner $owner, int $wishlistId): array
    {
        $list = $this->findList($owner, $wishlistId);
        if ($list === null) {
            return ['code' => 'not_found', 'name' => ''];
        }

        return $this->repository->withOwnerLock($owner->getShopId(), $owner->getOwnerKey(), function () use ($owner, $wishlistId, $list) {
            if (!$this->contains($this->getLists($owner), $wishlistId)) {
                return ['code' => 'not_found', 'name' => ''];
            }
            $this->repository->deleteList($wishlistId);
            $this->repository->ensureDefault($owner->getShopId(), $owner->getOwnerKey());

            return ['code' => 'deleted', 'name' => (string) $list['name']];
        });
    }

    /**
     * Moves one saved entry to another of the owner's lists, atomically.
     *
     * @return array{code: string, target_name: string} code: moved, already_in_target, same_list, not_found
     */
    public function move(WishlistOwner $owner, int $fromId, int $itemId, int $toId): array
    {
        $target = $this->findList($owner, $toId);
        if ($this->findList($owner, $fromId) === null || $target === null) {
            return ['code' => 'not_found', 'target_name' => ''];
        }
        if ($fromId === $toId) {
            return ['code' => 'same_list', 'target_name' => (string) $target['name']];
        }

        $code = $this->repository->withOwnerLock($owner->getShopId(), $owner->getOwnerKey(), function () use ($owner, $fromId, $itemId, $toId) {
            $lists = $this->getLists($owner);
            if (!$this->contains($lists, $fromId) || !$this->contains($lists, $toId)) {
                return 'not_found';
            }

            return $this->repository->moveItem($fromId, $itemId, $toId);
        });

        return ['code' => $code, 'target_name' => (string) $target['name']];
    }

    /**
     * For the move dialog: which of the owner's lists already hold each saved entry's variant.
     *
     * @param array[] $items raw item rows (id_wishlist_product, id_product, id_product_attribute)
     *
     * @return array<int, int[]> item ID => list IDs
     */
    public function itemMemberships(WishlistOwner $owner, array $items): array
    {
        $map = $this->repository->variantMemberships($owner->getShopId(), $owner->getOwnerKey());
        $result = [];
        foreach ($items as $item) {
            $result[(int) $item['id_wishlist_product']] = $map[$item['id_product'] . '-' . $item['id_product_attribute']] ?? [];
        }

        return $result;
    }

    /**
     * Picker data for one exact variant: every list of the owner with its membership.
     * The `in_list` values are the baseline the picker must send back on save.
     *
     * @param int[] $selectedAttributeIds PDP group[] choices
     *
     * @return array{code: string, id_product?: int, id_product_attribute?: int, product_name?: string, lists?: array[]}
     */
    public function membership(WishlistOwner $owner, int $productId, int $productAttributeId, array $selectedAttributeIds = []): array
    {
        $product = $this->catalog->findSavableProduct($productId);
        $resolved = $product ? $this->catalog->resolveCombination($product, $productAttributeId, $selectedAttributeIds) : null;
        if ($product === null || $resolved === null) {
            return ['code' => 'invalid'];
        }
        $in = array_flip($this->repository->listIdsContaining($owner->getShopId(), $owner->getOwnerKey(), (int) $product->id, $resolved));
        $lists = [];
        foreach ($this->getLists($owner) as $list) {
            $lists[] = $list + ['in_list' => isset($in[(int) $list['id_wishlist']])];
        }
        $label = $this->catalog->combinationLabel((int) $product->id, $resolved);

        return [
            'code' => 'ok',
            'id_product' => (int) $product->id,
            'id_product_attribute' => $resolved,
            'product_name' => (string) $product->name,
            'combination' => $label,
            'lists' => $lists,
        ];
    }

    /**
     * Applies the picker's edits relative to the baseline it was opened with. Only lists whose
     * checkbox changed are touched; an untouched list keeps whatever another tab did meanwhile.
     * If a touched list changed since the baseline, nothing is written (`stale`).
     * All targets are authorised and validated before any removal; any failure writes nothing.
     *
     * @param array<int, bool> $baseline list ID => was in list when the picker opened
     * @param array<int, bool> $desired  list ID => checked on save
     *
     * @return array{code: string, added: int[], removed: int[]} code: saved, unchanged, stale, not_found, invalid
     */
    public function saveMembership(WishlistOwner $owner, int $productId, int $productAttributeId, array $baseline, array $desired): array
    {
        $changes = [];
        foreach ($desired as $listId => $checked) {
            if (array_key_exists($listId, $baseline) && $baseline[$listId] !== $checked) {
                $changes[(int) $listId] = $checked;
            }
        }
        if (!$changes) {
            return ['code' => 'unchanged', 'added' => [], 'removed' => []];
        }

        // Additions need a product the visitor may save now; removals of existing entries don't.
        $fallbackName = null;
        if (in_array(true, $changes, true)) {
            $product = $this->catalog->findSavableProduct($productId);
            if ($product === null || !$this->catalog->combinationBelongsTo($product, $productAttributeId)) {
                return ['code' => 'invalid', 'added' => [], 'removed' => []];
            }
            $label = $this->catalog->combinationLabel($productId, $productAttributeId);
            $fallbackName = $label !== '' ? $product->name . ' (' . $label . ')' : (string) $product->name;
        }

        return $this->repository->withOwnerLock($owner->getShopId(), $owner->getOwnerKey(), function () use ($owner, $productId, $productAttributeId, $baseline, $changes, $fallbackName) {
            $lists = $this->getLists($owner);
            foreach (array_keys($changes) as $listId) {
                if (!$this->contains($lists, $listId)) {
                    return ['code' => 'not_found', 'added' => [], 'removed' => []];
                }
            }
            $current = array_flip($this->repository->listIdsContaining($owner->getShopId(), $owner->getOwnerKey(), $productId, $productAttributeId));
            foreach (array_keys($changes) as $listId) {
                if (isset($current[$listId]) !== $baseline[$listId]) {
                    return ['code' => 'stale', 'added' => [], 'removed' => []];
                }
            }

            $added = [];
            $removed = [];
            foreach ($changes as $listId => $checked) {
                if ($checked) {
                    $this->repository->addItem($listId, $productId, $productAttributeId, (string) $fallbackName);
                    $added[] = $listId;
                } else {
                    $this->repository->removeVariant($listId, $productId, $productAttributeId);
                    $removed[] = $listId;
                }
            }

            return ['code' => 'saved', 'added' => $added, 'removed' => $removed];
        });
    }

    /**
     * Cart-style claim on login (Stage 2 plan §2.1): guest lists are re-assigned to the account
     * in place; the guest default merges into the account default; a name clash is renamed
     * visibly ("Birthday (2)"), never merged by name. The list cap never drops incoming lists.
     *
     * @return array{items: int, renamed: int}
     */
    public function claimGuestLists(WishlistOwner $guest, WishlistOwner $customer): array
    {
        if (!$guest->isGuest() || $customer->isGuest() || $guest->getShopId() !== $customer->getShopId()) {
            return ['items' => 0, 'renamed' => 0];
        }

        return $this->repository->withOwnerLock($customer->getShopId(), $customer->getOwnerKey(), function () use ($guest, $customer) {
            return $this->repository->claimGuestLists(
                $customer->getShopId(),
                $guest->getOwnerKey(),
                $customer->getOwnerKey(),
                (int) $customer->getCustomerId(),
                [$this->validator, 'uniqueName']
            );
        });
    }

    private function contains(array $lists, int $wishlistId): bool
    {
        foreach ($lists as $list) {
            if ((int) $list['id_wishlist'] === $wishlistId) {
                return true;
            }
        }

        return false;
    }
}
