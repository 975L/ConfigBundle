<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Entity;

use App\Entity\User;
use c975L\ConfigBundle\Repository\ConfigRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: ConfigRepository::class)]
#[ORM\Table(name: 'site_config')]
#[UniqueEntity(fields: ['slug'], message: 'label.slug_exists')]
class Config
{
    public const TYPE_TEXT = 'text';
    public const TYPE_HTML = 'html';
    public const TYPE_BOOL = 'bool';
    public const TYPE_INT  = 'int';
    public const TYPE_DATE  = 'date';
    public const TYPE_JSON = 'json';

    public const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_HTML,
        self::TYPE_BOOL,
        self::TYPE_INT,
        self::TYPE_DATE,
        self::TYPE_JSON,
    ];

    public const GROUP_SYSTEM = 'system';
    public const GROUP_GENERAL = 'general';
    public const GROUP_LEGAL = 'legal';
    public const GROUP_CREDITS = 'credits';
    public const GROUP_ANALYTICS = 'analytics';
    public const GROUP_BACKUP = 'backup';
    public const GROUP_EMAIL = 'email';
    public const GROUP_FORM = 'form';
    public const GROUP_SECURITY = 'security';
    public const GROUP_SHOP = 'shop';
    public const GROUP_PAYMENT = 'payment';

    public const GROUPS = [
        self::GROUP_SYSTEM,
        self::GROUP_GENERAL,
        self::GROUP_LEGAL,
        self::GROUP_CREDITS,
        self::GROUP_ANALYTICS,
        self::GROUP_BACKUP,
        self::GROUP_EMAIL,
        self::GROUP_FORM,
        self::GROUP_SECURITY,
        self::GROUP_SHOP,
        self::GROUP_PAYMENT,
    ];

    public const SEVERITY_DANGER = 'danger';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_INFO = 'info';

    public const SEVERITIES = [
        self::SEVERITY_DANGER,
        self::SEVERITY_WARNING,
        self::SEVERITY_INFO,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private string $label;

    #[ORM\Column(name: 'slug', length: 100, unique: true)]
    #[Assert\NotBlank]
    private string $slug;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    #[Assert\Type(type: 'bool')]
    private ?bool $isSensitive = null;

    // Restricts the config (regardless of group) to ROLE_SUPER_ADMIN only, for secrets shared across
    // an install (backup DB credentials, payment API keys...) that a regular site admin must never see
    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    #[Assert\Type(type: 'bool')]
    private ?bool $isRestricted = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $value = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::TYPES)]
    private string $kind = self::TYPE_TEXT;

    // Column name is backtick-quoted because `group` is a reserved SQL keyword
    // (MySQL/MariaDB): without this, Doctrine emits unquoted `group` in generated
    // SQL and every UPDATE/INSERT fails with a syntax error
    #[ORM\Column(name: '`group`', length: 20, nullable: true)]
    #[Assert\Choice(choices: self::GROUPS)]
    private ?string $group = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Choice(choices: self::SEVERITIES)]
    private ?string $severity = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $creation = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $modification = null;

    #[ORM\ManyToOne]
    private ?User $user = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getIsSensitive(): ?bool
    {
        return $this->isSensitive;
    }

    public function setIsSensitive(?bool $isSensitive): static
    {
        $this->isSensitive = $isSensitive;

        return $this;
    }

    public function getIsRestricted(): ?bool
    {
        return $this->isRestricted;
    }

    public function setIsRestricted(?bool $isRestricted): static
    {
        $this->isRestricted = $isRestricted;

        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(mixed $value): static
    {
        $this->value = match (true) {
            null === $value => null,
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            default => (string) $value,
        };

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getGroup(): ?string
    {
        return $this->group;
    }

    public function setGroup(?string $group): static
    {
        $this->group = $group;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getSeverity(): ?string
    {
        return $this->severity;
    }

    public function setSeverity(?string $severity): static
    {
        $this->severity = $severity;

        return $this;
    }

    public function getCreation(): ?\DateTimeInterface
    {
        return $this->creation  ;
    }

    public function setCreation(\DateTimeInterface $creation): self
    {
        $this->creation = $creation;

        return $this;
    }

    public function getModification(): ?\DateTimeInterface
    {
        return $this->modification;
    }

    public function setModification(\DateTimeInterface $modification): self
    {
        $this->modification = $modification;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    // Validates that a "json" kind config always holds valid JSON, since its value is edited as raw text
    #[Assert\Callback]
    public function validateJsonValue(ExecutionContextInterface $context): void
    {
        if (self::TYPE_JSON === $this->kind
            && null !== $this->value
            && '' !== $this->value
            && null === json_decode($this->value)
        ) {
            $context->buildViolation('label.invalid_json')
                ->atPath('value')
                ->addViolation();
        }
    }
}