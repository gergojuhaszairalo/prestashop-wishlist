<?php

declare(strict_types=1);

namespace Summit\Wishlist\Controller;

use Tools;

/**
 * Shared response handling for the module's POST controllers: JSON for the
 * JavaScript enhancement, otherwise a redirect back with flash notifications.
 * Used only inside ModuleFrontController subclasses.
 */
trait RespondsToShopper
{
    protected function isPostRequest(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    protected function wantsJson(): bool
    {
        return (bool) Tools::getValue('ajax')
            || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    }

    /**
     * @param string[] $messages
     */
    protected function respond(bool $success, array $messages, array $data = []): void
    {
        if ($this->wantsJson()) {
            // JSON bypasses smartyOutputContent(), where core normally writes the cookie,
            // and echoing first would block the destructor's write. Write it exactly once here.
            $this->context->cookie->write();
            header('Content-Type: application/json');
            $this->ajaxRender(json_encode(['success' => $success, 'messages' => $messages] + $data));
            exit;
        }

        foreach ($messages as $message) {
            if ($success) {
                $this->success[] = $message;
            } else {
                $this->errors[] = $message;
            }
        }
        $this->redirectWithNotifications($this->backUrl());
    }

    /** @var string explicit no-JS return target (e.g. the list an action came from); wins over the Referer */
    protected $returnTo = '';

    private function backUrl(): string
    {
        if ($this->returnTo !== '') {
            return $this->returnTo;
        }
        $referrer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($referrer !== '' && Tools::urlBelongsToShop($referrer)) {
            return $referrer;
        }

        return $this->module->getWishlistUrl();
    }
}
