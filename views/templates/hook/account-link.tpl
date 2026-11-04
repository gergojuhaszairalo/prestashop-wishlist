{* Used in both the My Account tiles and the account sidebar; both render account-menu links. *}
<a class="account-menu__link{if $swl_active} account-menu__link--active{/if}" id="summitwishlist_link" href="{$swl_url|escape:'htmlall':'UTF-8'}"{if $swl_active} aria-current="page"{/if}>
  <i class="account-menu__icon material-icons" aria-hidden="true">&#xE87E;</i>
  {l s='My wishlist' d='Modules.Summitwishlist.Shop'}
</a>
