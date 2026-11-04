<?php

declare(strict_types=1);

namespace Summit\Wishlist\Owner;

use Context;

/**
 * Resolves the current visitor from the PrestaShop context, never from request input.
 * Guests get a random token in the (encrypted) front cookie; only its hash is stored.
 */
class OwnerResolver
{
    private const GUEST_COOKIE_KEY = 'swl_guest';

    /** @var Context */
    private $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    public function resolve(): WishlistOwner
    {
        $shopId = (int) $this->context->shop->id;
        $customer = $this->context->customer;

        if ($customer && $customer->isLogged()) {
            return new WishlistOwner($shopId, 'customer:' . (int) $customer->id, (int) $customer->id);
        }

        $token = $this->guestToken();
        if ($token === null) {
            // Only set the value; core writes the cookie once per response. Every explicit
            // write() emits another full Set-Cookie header and can overflow nginx's buffer.
            $token = bin2hex(random_bytes(32));
            $this->context->cookie->{self::GUEST_COOKIE_KEY} = $token;
        }

        return new WishlistOwner($shopId, 'guest:' . hash('sha256', $token), null);
    }

    /**
     * The guest identity still held by a logged-in visitor's cookie, i.e. a guest list
     * waiting to be claimed. Covers every login path (form, registration, password reset,
     * cart recovery, social login) because it hangs off the cookie, like the core cart.
     */
    public function pendingGuestOwner(): ?WishlistOwner
    {
        $customer = $this->context->customer;
        $token = $this->guestToken();
        if (!$customer || !$customer->isLogged() || $token === null) {
            return null;
        }

        return new WishlistOwner((int) $this->context->shop->id, 'guest:' . hash('sha256', $token), null);
    }

    /**
     * After a claim, forget the guest identity so a later logout starts a fresh, empty list.
     */
    public function forgetGuest(): void
    {
        unset($this->context->cookie->{self::GUEST_COOKIE_KEY});
    }

    private function guestToken(): ?string
    {
        $token = (string) $this->context->cookie->{self::GUEST_COOKIE_KEY};

        return preg_match('/^[a-f0-9]{64}$/', $token) ? $token : null;
    }
}
