<?php

declare(strict_types=1);

namespace Summit\Wishlist\Controller\Admin;

use PrestaShop\PrestaShop\Core\Grid\GridFactoryInterface;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Summit\Wishlist\Admin\WishlistDetailProvider;
use Summit\Wishlist\Grid\Definition\Factory\WishlistGridDefinitionFactory;
use Summit\Wishlist\Grid\Filters\WishlistFilters;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customers > Wishlists (Stage 3): read-only. Listing and detail are GET; the only POST is the
 * core grid search, which stores the employee's filters and redirects. Nothing here changes a
 * wishlist, and there is no route that could.
 */
class WishlistController extends PrestaShopAdminController
{
    public const TAB_CLASS_NAME = 'AdminSummitWishlist';

    private const DOMAIN = 'Modules.Summitwishlist.Admin';

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function indexAction(
        WishlistFilters $filters,
        #[Autowire(service: 'summitwishlist.grid.factory')]
        GridFactoryInterface $wishlistGridFactory,
    ): Response {
        return $this->render('@Modules/summitwishlist/views/templates/admin/wishlist/index.html.twig', [
            'enableSidebar' => true,
            'layoutTitle' => $this->trans('Wishlists', [], self::DOMAIN),
            'wishlistGrid' => $this->presentGrid($wishlistGridFactory->getGrid($filters)),
        ]);
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function searchAction(
        Request $request,
        WishlistGridDefinitionFactory $definitionFactory,
    ): RedirectResponse {
        return $this->buildSearchResponse(
            $definitionFactory,
            $request,
            WishlistGridDefinitionFactory::GRID_ID,
            'summitwishlist_admin_index'
        );
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function viewAction(int $wishlistId, WishlistDetailProvider $detailProvider): Response
    {
        $wishlist = $detailProvider->getDetail($wishlistId);

        // Unknown and out-of-scope lists get the same generic page: no name, owner or count.
        $response = $this->render('@Modules/summitwishlist/views/templates/admin/wishlist/view.html.twig', [
            'enableSidebar' => true,
            'layoutTitle' => $wishlist !== null
                ? $this->trans('Wishlist: %name%', ['%name%' => $wishlist['name']], self::DOMAIN)
                : $this->trans('Wishlist not available', [], self::DOMAIN),
            'wishlist' => $wishlist,
        ]);
        if ($wishlist === null) {
            $response->setStatusCode(Response::HTTP_NOT_FOUND);
        }

        return $response;
    }
}
