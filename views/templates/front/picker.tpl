{extends file='page.tpl'}

{* No-JS fallback for the membership dialog: the same form, as a page. *}
{block name='page_title'}
  {l s='Save to wishlists' d='Modules.Summitwishlist.Shop'}
{/block}

{block name='page_content'}
  <section class="sw-wishlist sw-wishlist--picker">
    <div class="sw-dialog sw-dialog--page">
      {include file='module:summitwishlist/views/templates/_partials/picker-form.tpl'}
    </div>
  </section>
{/block}
