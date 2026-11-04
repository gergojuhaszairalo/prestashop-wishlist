<?php

declare(strict_types=1);

namespace Summit\Wishlist\Grid\Data;

use PrestaShop\PrestaShop\Core\Grid\Data\Factory\GridDataFactoryInterface;
use PrestaShop\PrestaShop\Core\Grid\Data\GridData;
use PrestaShop\PrestaShop\Core\Grid\Record\RecordCollection;
use PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Labels list owners for display. Guests are shown as "Guest" with no email and never with their
 * owner key (a hash of their cookie token), which is dropped from the records here.
 */
class WishlistGridDataFactory implements GridDataFactoryInterface
{
    public function __construct(
        private readonly GridDataFactoryInterface $doctrineDataFactory,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getData(SearchCriteriaInterface $searchCriteria)
    {
        $data = $this->doctrineDataFactory->getData($searchCriteria);

        $records = [];
        foreach ($data->getRecords()->all() as $record) {
            $records[] = self::presentOwner($record, $this->translator);
        }

        return new GridData(new RecordCollection($records), $data->getRecordsTotal(), $data->getQuery());
    }

    /**
     * Shared with the detail page so both describe owners the same way.
     *
     * @param array<string, mixed> $row with owner_key, id_customer, customer_found, owner_name, email
     */
    public static function presentOwner(array $row, TranslatorInterface $translator): array
    {
        $isCustomer = !empty($row['customer_found']);
        if (!$isCustomer) {
            $row['owner_name'] = str_starts_with((string) $row['owner_key'], 'guest:')
                ? $translator->trans('Guest', [], 'Modules.Summitwishlist.Admin')
                : $translator->trans('Deleted customer', [], 'Modules.Summitwishlist.Admin');
            $row['email'] = '';
        }
        $row['is_guest'] = !$isCustomer;
        unset($row['owner_key'], $row['customer_found']);

        return $row;
    }
}
