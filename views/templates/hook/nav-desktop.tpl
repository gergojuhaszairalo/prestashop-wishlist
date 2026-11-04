{* displayNav2 is inside a d-none d-md-block bar; nav-mobile.tpl covers small screens.
   Same header-block structure as the theme's cart entry, so alignment, spacing and badge match. *}
<div class="sw-nav sw-nav--desktop">
  <div class="header-block d-flex align-items-center{if $swl_count > 0} header-block--active{/if}">
    <a class="header-block__action-btn" href="{$swl_url|escape:'htmlall':'UTF-8'}"
       aria-label="{l s='Wishlist (%count% items)' sprintf=['%count%' => $swl_count] d='Modules.Summitwishlist.Shop'}">
      <i class="material-icons header-block__icon" aria-hidden="true">&#xE87E;</i>
      <span class="d-none d-md-flex header-block__title">{l s='Wishlist' d='Modules.Summitwishlist.Shop'}</span>
      <span class="header-block__badge js-sw-count">{$swl_count|intval}</span>
    </a>
  </div>
</div>
