<?php

namespace PrestaShop\Module\PsOnePageCheckout\Translation;

use Symfony\Contracts\Translation\TranslatorInterface;

final class ModuleTranslation
{
    public const ADMIN_DOMAIN = 'Modules.Onepagecheckout.Admin';
    public const SHOP_DOMAIN = 'Modules.Onepagecheckout.Shop';

    public static function translate(
        TranslatorInterface $translator,
        string $message,
        string $domain = self::SHOP_DOMAIN,
    ): string {
        $translated = trim((string) $translator->trans($message, [], $domain));

        return $translated !== '' ? $translated : $message;
    }
}
