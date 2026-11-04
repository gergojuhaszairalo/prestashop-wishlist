{* Guest persistence notice. Signing in moves these items into the account list. *}
{if $swl_is_guest}
  <div class="sw-notice" role="note">
    <i class="material-icons sw-notice__icon" aria-hidden="true">&#xE88E;</i>
    <p class="sw-notice__text">
      {* One translatable sentence; the link markup is injected through sprintf (PS 9 dropped tags=). *}
      {l s='Your wishlist is saved in this browser. %link_start%Sign in or create an account%link_end% to keep it on every device.'
         sprintf=['%link_start%' => "<a href=\"{$swl_login_url|escape:'htmlall':'UTF-8'}\">", '%link_end%' => '</a>']
         d='Modules.Summitwishlist.Shop'}
    </p>
  </div>
{/if}
