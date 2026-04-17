<?php

namespace PrestaShop\Module\PsOnePageCheckout\Form;

use Symfony\Contracts\Translation\TranslatorInterface;

class OnePageCheckoutAddressFormatter implements \FormFormatterInterface
{
    use AddressFieldsFormatTrait;

    protected \Country $country;
    protected TranslatorInterface $translator;

    /**
     * @var array<int, mixed>
     */
    protected array $availableCountries;

    /**
     * @var array<string, mixed>
     */
    protected array $definition;

    /**
     * @param array<int, mixed> $availableCountries
     */
    public function __construct(
        \Country $country,
        TranslatorInterface $translator,
        array $availableCountries,
    ) {
        $this->country = $country;
        $this->translator = $translator;
        $this->availableCountries = $availableCountries;
        $this->definition = \Address::$definition['fields'];
    }

    public function setCountry(\Country $country): self
    {
        $this->country = $country;

        return $this;
    }

    public function getCountry(): \Country
    {
        return $this->country;
    }

    /**
     * @return array<string,\FormField>
     */
    public function getFormat(): array
    {
        $format = $this->addConstraints(
            $this->addMaxLength(
                $this->getAddressFieldsFormat('', false)
            )
        );

        $additionalAddressFormFields = \Hook::exec('additionalCustomerAddressFields', ['fields' => &$format], null, true);
        if (is_array($additionalAddressFormFields)) {
            foreach ($additionalAddressFormFields as $moduleName => $additionalFormFields) {
                if (!is_array($additionalFormFields)) {
                    continue;
                }

                foreach ($additionalFormFields as $formField) {
                    if (!$formField instanceof \FormField) {
                        continue;
                    }

                    $formField->moduleName = $moduleName;
                    $format[$moduleName . '_' . $formField->getName()] = $formField;
                }
            }
        }

        return $format;
    }
}
