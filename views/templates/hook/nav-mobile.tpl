{* displayTop, inside the header row: mobile-only entry using the native header-block structure. *}
<div class="sw-nav sw-nav--mobile d-md-none d-flex col-auto">
  <div class="header-block">
    <a class="header-block__action-btn" href="{$swl_url|escape:'htmlall':'UTF-8'}"
       aria-label="{if $swl_default_name}{l s='Wishlist: %list%, %count% items' sprintf=['%list%' => $swl_default_name, '%count%' => $swl_count] d='Modules.Summitwishlist.Shop'}{else}{l s='Wishlist (%count% items)' sprintf=['%count%' => $swl_count] d='Modules.Summitwishlist.Shop'}{/if}">
      <i class="material-icons header-block__icon" aria-hidden="true">&#xE87E;</i>
      <span class="header-block__badge js-sw-count">{$swl_count|intval}</span>
    </a>
  </div>
</div>
