<?php

declare(strict_types=1);

namespace Summit\Wishlist\Security;

use Cookie;
use Summit\Wishlist\Owner\WishlistOwner;

/**
 * Per-visitor CSRF token. Tools::getToken() is identical for every guest, so the module
 * derives its own: an HMAC (keyed with the shop's secret cookie key) over the owner
 * identity and the customer session token.
 * - Guests: the owner key embeds the hash of a random per-browser token, so the CSRF token is per browser.
 * - Customers: the core session_token changes on every login, so the token is per session.
 * - A login or logout changes the owner and therefore the token.
 * It is stateless on purpose: storing it in the PrestaShop cookie grew every Set-Cookie
 * header and, combined with core's repeated writes on login, overflowed nginx's header buffer.
 */
class CsrfTokenManager
{
    public const FIELD = 'swl_token';

    /** Keys used by 1.0.0 to store the token; removed from visitors' cookies on sight. */
    private const LEGACY_KEYS = ['swl_csrf', 'swl_csrf_owner'];

    /** @var Cookie */
    private $cookie;
    /** @var string */
    private $secret;

    public function __construct(Cookie $cookie, string $secret)
    {
        $this->cookie = $cookie;
        $this->secret = $secret;
    }

    public function getToken(WishlistOwner $owner): string
    {
        foreach (self::LEGACY_KEYS as $key) {
            if (isset($this->cookie->{$key})) {
                unset($this->cookie->{$key});
            }
        }

        return hash_hmac(
            'sha256',
            'summitwishlist-csrf|' . $owner->getShopId() . '|' . $owner->getOwnerKey() . '|' . (string) $this->cookie->session_token,
            $this->secret
        );
    }

    public function isValid(WishlistOwner $owner, $submitted): bool
    {
        return is_string($submitted) && $submitted !== '' && hash_equals($this->getToken($owner), $submitted);
    }
}
