{* List switcher: tabs on desktop, a native select on mobile (works without JS via the Go button). *}
<nav class="sw-switcher" aria-label="{l s='Your wishlists' d='Modules.Summitwishlist.Shop'}">
  <ul class="sw-switcher__tabs list-unstyled d-none d-md-flex">
    {foreach from=$swl_lists item=list}
      <li>
        <a class="sw-switcher__tab{if $list.is_current} is-current{/if}" href="{$list.url|escape:'htmlall':'UTF-8'}"{if $list.is_current} aria-current="page"{/if}>
          <span class="sw-switcher__name">{$list.name|escape:'htmlall':'UTF-8'}</span>
          <span class="badge rounded-pill sw-switcher__count">{$list.count|intval}</span>
        </a>
      </li>
    {/foreach}
  </ul>
  <form class="sw-switcher__select d-md-none" action="{$swl_manage_url|escape:'htmlall':'UTF-8'}" method="get">
    {* A GET form drops the action's own query string, so carry the route for non-friendly URLs. *}
    <input type="hidden" name="fc" value="module">
    <input type="hidden" name="module" value="summitwishlist">
    <input type="hidden" name="controller" value="wishlist">
    <label class="form-label" for="sw-switcher-select">{l s='Show wishlist' d='Modules.Summitwishlist.Shop'}</label>
    <div class="sw-switcher__row">
      <select class="form-select" id="sw-switcher-select" name="list" data-sw-switcher>
        {foreach from=$swl_lists item=list}
          <option value="{$list.id|intval}"{if $list.is_current} selected{/if}>{$list.name|escape:'htmlall':'UTF-8'} ({$list.count|intval})</option>
        {/foreach}
      </select>
      <button type="submit" class="btn btn-outline-primary sw-action" data-sw-switcher-go>{l s='Show' d='Modules.Summitwishlist.Shop'}</button>
    </div>
  </form>
</nav>
