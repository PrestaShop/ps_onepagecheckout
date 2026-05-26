<?php

if (!interface_exists('FormFormatterInterface', false)) {
    interface FormFormatterInterface
    {
        public function getFormat();
    }
}

if (!class_exists('FormField', false)) {
    class FormField
    {
        public ?string $moduleName = null;
        private string $name = '';
        private string $type = 'text';
        private bool $required = false;
        private string $label = '';
        private mixed $value = null;
        private array $availableValues = [];
        private ?int $minLength = null;
        private ?int $maxLength = null;
        private array $constraints = [];
        private array $errors = [];

        public function setName($name): self
        {
            $this->name = (string) $name;

            return $this;
        }

        public function getName(): string
        {
            return $this->name;
        }

        public function setType($type): self
        {
            $this->type = (string) $type;

            return $this;
        }

        public function getType(): string
        {
            return $this->type;
        }

        public function setRequired($required): self
        {
            $this->required = (bool) $required;

            return $this;
        }

        public function isRequired(): bool
        {
            return $this->required;
        }

        public function setLabel($label): self
        {
            $this->label = (string) $label;

            return $this;
        }

        public function getLabel(): string
        {
            return $this->label;
        }

        public function setValue($value): self
        {
            $this->value = $value;

            return $this;
        }

        public function getValue(): mixed
        {
            return $this->value;
        }

        public function addAvailableValue($value, $label = null): self
        {
            $this->availableValues[$value] = $label ?? $value;

            return $this;
        }

        public function setAvailableValues($availableValues): self
        {
            $this->availableValues = (array) $availableValues;

            return $this;
        }

        public function getAvailableValues(): array
        {
            return $this->availableValues;
        }

        public function setMinLength($minLength): self
        {
            $this->minLength = (int) $minLength;

            return $this;
        }

        public function getMinLength(): ?int
        {
            return $this->minLength;
        }

        public function setMaxLength($maxLength): self
        {
            $this->maxLength = (int) $maxLength;

            return $this;
        }

        public function getMaxLength(): ?int
        {
            return $this->maxLength;
        }

        public function addConstraint($constraint): self
        {
            $this->constraints[] = $constraint;

            return $this;
        }

        public function getConstraints(): array
        {
            return $this->constraints;
        }

        public function addError($error): self
        {
            $this->errors[] = $error;

            return $this;
        }

        public function setErrors(array $errors): self
        {
            $this->errors = $errors;

            return $this;
        }

        public function getErrors(): array
        {
            return $this->errors;
        }

        public function toArray(): array
        {
            return [
                'name' => $this->name,
                'type' => $this->type,
                'required' => $this->required,
                'label' => $this->label,
                'value' => $this->value,
                'availableValues' => $this->availableValues,
                'minLength' => $this->minLength,
                'maxLength' => $this->maxLength,
                'constraints' => $this->constraints,
                'errors' => $this->errors,
            ];
        }
    }
}

if (!class_exists('Country', false)) {
    class Country
    {
        public static array $registry = [];
        public const GEOLOC_ALLOWED = 1;
        public int $id = 0;
        public bool $need_zip_code = false;
        public string $zip_code_format = '';
        public bool $need_identification_number = false;
        public bool $contains_states = false;
        public array $validZipCodes = [];

        public function __construct(int $id = 0, int $idLang = 0)
        {
            if ($id > 0 && isset(self::$registry[$id])) {
                $data = self::$registry[$id];
                $this->id = $id;
                $this->need_zip_code = (bool) ($data['need_zip_code'] ?? false);
                $this->zip_code_format = (string) ($data['zip_code_format'] ?? '');
                $this->need_identification_number = (bool) ($data['need_identification_number'] ?? false);
                $this->contains_states = (bool) ($data['contains_states'] ?? false);
                $this->validZipCodes = (array) ($data['validZipCodes'] ?? []);
            }
        }

        public function checkZipCode($postcode): bool
        {
            return in_array((string) $postcode, $this->validZipCodes, true);
        }

        public static function getCountries($idLang = 0, $active = false): array
        {
            return array_map(static function (array $country, int $countryId): array {
                return [
                    'id_country' => $countryId,
                    'name' => (string) ($country['name'] ?? ''),
                ];
            }, self::$registry, array_keys(self::$registry));
        }
    }
}

if (!class_exists('Address', false)) {
    class Address
    {
        public static array $definition = ['fields' => []];
        public $id = 0;
        public $id_customer = 0;
        public $id_country = 0;
        public $id_state = 0;
        public $alias = '';
        public $firstname = '';
        public $lastname = '';
        public $address1 = '';
        public $address2 = '';
        public $postcode = '';
        public $city = '';
        public $phone = '';
        public $dni = '';
        public $company = '';

        public function __construct($id = null, $idLang = null)
        {
            if ($id !== null) {
                $this->id = (int) $id;
            }
        }
    }
}

if (!class_exists('Customer', false)) {
    class Customer
    {
        public static array $requiredFields = [];
        public $id = 0;
        public $is_guest = 0;
        public $email = '';
        public $firstname = '';
        public $lastname = '';
        public $id_country = 0;
        public $id_gender = 0;
        public $id_risk = 0;
        public $passwd = '';

        public function __construct($id = null)
        {
            if ($id !== null) {
                $this->id = (int) $id;
            }
        }

        public function isFieldRequired($fieldName): bool
        {
            return in_array($fieldName, self::$requiredFields, true);
        }

        public function isGuest(): bool
        {
            return false;
        }

        public function isLogged($withGuest = false): bool
        {
            return false;
        }

        public function getSimpleAddresses($idLang = null): array
        {
            return [];
        }

        public function getAddresses($idLang = null): array
        {
            return [];
        }
    }
}

if (!class_exists('OpcLegacyContextCustomerStub', false)) {
    class OpcLegacyContextCustomerStub
    {
        public function isLogged($withGuest = false): bool
        {
            return false;
        }
    }
}

if (!class_exists('OpcLegacyContextTranslatorStub', false)) {
    class OpcLegacyContextTranslatorStub
    {
        public function trans($id, array $parameters = [], $domain = null, $locale = null): string
        {
            return strtr((string) $id, $parameters);
        }
    }
}

if (!class_exists('Context', false)) {
    class Context
    {
        public static ?self $instance = null;
        public mixed $customer = null;
        public mixed $cart = null;
        public mixed $cookie = null;
        public mixed $language = null;
        public mixed $country = null;
        public mixed $shop = null;
        public mixed $smarty = null;
        public mixed $link = null;
        public mixed $controller = null;
        public mixed $translator = null;

        public static function getContext(): self
        {
            if (self::$instance === null) {
                self::$instance = new self();
                self::$instance->customer = new OpcLegacyContextCustomerStub();
            }

            return self::$instance;
        }

        public function getTranslator()
        {
            if ($this->translator === null) {
                $this->translator = new OpcLegacyContextTranslatorStub();
            }

            return $this->translator;
        }
    }
}

if (!class_exists('Configuration', false)) {
    class Configuration
    {
        public static array $values = [];

        public static function get($key)
        {
            return self::$values[$key] ?? false;
        }

        public static function resetStaticCache(): void
        {
        }
    }
}

if (!class_exists('Carrier', false)) {
    class Carrier
    {
        public static function getDeliveredCountries($idLang = 0, $active = false, $groupByZone = false): array
        {
            return Country::getCountries($idLang, $active);
        }
    }
}

if (!class_exists('AddressFormat', false)) {
    class AddressFormat
    {
        public static array $orderedFields = [];
        public static array $requiredFields = [];

        public static function getOrderedAddressFields($idCountry = 0, $splitAll = false, $cleaned = false): array
        {
            return self::$orderedFields;
        }

        public static function getFieldsRequired(): array
        {
            return self::$requiredFields;
        }
    }
}

if (!class_exists('State', false)) {
    class State
    {
        public static array $statesByCountry = [];

        public static function getStatesByIdCountry($idCountry, $active = false, $orderBy = null, $sort = 'ASC'): array
        {
            return self::$statesByCountry[(int) $idCountry] ?? [];
        }
    }
}

if (!class_exists('Hook', false)) {
    class Hook
    {
        public static array $responses = [];

        public static function exec($hookName, $hookArgs = [], $idModule = null, $arrayReturn = false)
        {
            return self::$responses[$hookName] ?? ($arrayReturn ? [] : null);
        }
    }
}
