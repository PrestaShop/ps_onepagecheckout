<?php

namespace PrestaShop\Module\PsOnePageCheckout\Form;

use Symfony\Contracts\Translation\TranslatorInterface;

class OnePageCheckoutAddressForm extends \AbstractForm
{
    use AddressFormValidationTrait;

    /**
     * @var OnePageCheckoutAddressFormatter
     */
    protected $formatter;

    private \Language $language;

    public function __construct(
        \Smarty $smarty,
        \Language $language,
        TranslatorInterface $translator,
        OnePageCheckoutAddressFormatter $formatter,
    ) {
        parent::__construct(
            $smarty,
            $translator,
            $formatter
        );

        $this->language = $language;
        $this->formatter = $formatter;
    }

    /**
     * @param array<string,mixed> $requestParameters
     */
    public function fillFromRequest(array $requestParameters, string $prefix = ''): self
    {
        $this->syncFormatterCountryFromRequest($requestParameters, $prefix);

        if (!$this->formFields) {
            $this->formFields = $this->formatter->getFormat();
        }

        $params = [];
        foreach ($this->formFields as $key => $field) {
            $fieldName = $field->getName();
            $params[$fieldName] = $requestParameters[$prefix . $fieldName] ?? $requestParameters[$fieldName]
                ?? $requestParameters[$prefix . $key] ?? $requestParameters[$key] ?? null;
        }

        return $this->fillWith($params);
    }

    public function fillWith(array $params = [])
    {
        if (isset($params['id_country']) && (int) $params['id_country'] > 0) {
            $country = (int) $params['id_country'] !== (int) $this->formatter->getCountry()->id
                ? new \Country((int) $params['id_country'], (int) $this->language->id)
                : $this->formatter->getCountry();

            $this->formatter->setCountry($country);
        }

        return parent::fillWith($params);
    }

    /**
     * @param array<string,mixed> $requestParameters
     */
    private function syncFormatterCountryFromRequest(array $requestParameters, string $prefix): void
    {
        $requestedCountryId = (int) ($requestParameters[$prefix . 'id_country'] ?? $requestParameters['id_country'] ?? 0);
        if ($requestedCountryId <= 0) {
            return;
        }

        if ((int) $this->formatter->getCountry()->id !== $requestedCountryId) {
            $this->formatter->setCountry(new \Country($requestedCountryId, (int) $this->language->id));
        }

        // Rebuild the allowed fields from the requested country before extracting request values.
        $this->formFields = [];
    }

    public function validate()
    {
        $country = $this->formatter->getCountry();
        $isValid = $this->validateAddressPostcode(
            $this->getField('postcode'),
            $country
        );

        if ($isValid) {
            $isValid = $this->validateAddressFormHook();
        }

        return $isValid && parent::validate();
    }

    /**
     * @return \Address|false
     */
    public function submit()
    {
        if (!$this->validate()) {
            return false;
        }

        return $this->buildAddress();
    }

    public function buildAddress(?\Address $address = null): \Address
    {
        $address = $address ?? new \Address(null, (int) $this->language->id);

        foreach ($this->formFields as $field) {
            $fieldName = $field->getName();
            if (property_exists($address, $fieldName)) {
                $address->{$fieldName} = $field->getValue();
            }
        }

        if (!$this->getField('id_state')) {
            $address->id_state = 0;
        }

        return $address;
    }

    /**
     * @return list<string>
     */
    public function getAllowedFieldNames(): array
    {
        if (!$this->formFields) {
            $this->formFields = $this->formatter->getFormat();
        }

        return array_keys($this->formFields);
    }

    /**
     * @return array<string,mixed>
     */
    public function getTemplateVariables()
    {
        if (!$this->formFields) {
            $this->formFields = $this->formatter->getFormat();
        }

        return [
            'errors' => $this->getErrors(),
            'formFields' => array_map(
                static function (\FormField $field): array {
                    return $field->toArray();
                },
                $this->formFields
            ),
        ];
    }
}
