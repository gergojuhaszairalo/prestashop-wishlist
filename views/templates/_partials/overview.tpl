{* All lists as independent cards. Actions stay visible (no hover-only controls). *}
<div class="sw-overview-head">
  <p class="sw-wishlist__count" id="sw-wishlist-count">
    {l s='%count% wishlists' sprintf=['%count%' => $swl_lists|count] d='Modules.Summitwishlist.Shop'}
  </p>
  <a class="btn btn-primary sw-action" href="{$swl_self_url|escape:'htmlall':'UTF-8'}&amp;dialog=create" data-sw-confirm="sw-create-dialog">
    <i class="material-icons" aria-hidden="true">&#xE145;</i>
    {l s='Create wishlist' d='Modules.Summitwishlist.Shop'}
  </a>
</div>

{include file='module:summitwishlist/views/templates/_partials/feedback.tpl'}

<ul class="sw-cards list-unstyled">
  {foreach from=$swl_lists item=card}
    <li class="sw-card{if $card.is_default} sw-card--default{/if}">
      <div class="sw-card__head">
        <h2 class="sw-card__name"><a href="{$card.url|escape:'htmlall':'UTF-8'}">{$card.name|escape:'htmlall':'UTF-8'}</a></h2>
        {if $card.is_default}
          <span class="badge text-bg-primary sw-badge-default">{l s='Default' d='Modules.Summitwishlist.Shop'}</span>
        {/if}
      </div>
      <p class="sw-card__count">
        {if $card.count == 1}
          {l s='1 saved item' d='Modules.Summitwishlist.Shop'}
        {else}
          {l s='%count% saved items' sprintf=['%count%' => $card.count] d='Modules.Summitwishlist.Shop'}
        {/if}
      </p>
      {include file='module:summitwishlist/views/templates/_partials/list-actions.tpl' swl_card=$card swl_context='card'}
    </li>
  {/foreach}
</ul>

{foreach from=$swl_lists item=card}
  {include file='module:summitwishlist/views/templates/_partials/name-dialog.tpl' swl_dialog_mode='rename' swl_dialog_target=$card}
  {include file='module:summitwishlist/views/templates/_partials/delete-dialog.tpl' swl_dialog_target=$card}
{/foreach}
