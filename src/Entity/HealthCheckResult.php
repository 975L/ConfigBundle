<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Entity;

use c975L\ConfigBundle\Repository\HealthCheckResultRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

// One row per (url, kind) check run, appended by HealthCheckRunner - kept as history rather than upserted in place, so the "Health check" dashboard page (HealthCheckResultRepository::findLatestPerUrlAndKind()) can later grow a trend view without a schema change. No pruning yet: weekly runs across a site's pages stay a modest row count, see ConfigBundle's README for the growth math before adding one
#[ORM\Entity(repositoryClass: HealthCheckResultRepository::class)]
#[ORM\Table(name: 'site_health_check_result')]
#[ORM\Index(columns: ['url', 'kind', 'checked_at'], name: 'idx_health_check_url_kind_date')]
class HealthCheckResult
{
    public const STATUS_OK = 'ok';
    public const STATUS_WARNING = 'warning';
    public const STATUS_ERROR = 'error';
    // Not ok/warning/error: the check itself never ran (eg. the page doesn't resolve on the checked environment yet) - kept visually neutral rather than a warning/error color, since there's nothing actionable about a page that simply isn't deployed there
    public const STATUS_SKIPPED = 'skipped';

    public const STATUSES = [
        self::STATUS_OK,
        self::STATUS_WARNING,
        self::STATUS_ERROR,
        self::STATUS_SKIPPED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Provider identifier, eg. "pagespeed" (see HealthCheckProviderInterface::getKind())
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private string $kind;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $url;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label = null;

    // Admin CRUD edit URL for the entity behind this row (eg. SiteBundle's Page edit screen), null when the check has no such counterpart (eg. site-wide checks) - kept separate from url, which is the actually-tested public address
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $editUrl = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::STATUSES)]
    private string $status;

    // Short human-readable line shown in the dashboard table, eg. "Perf 82 · A11y 91 · Bonnes pratiques 100 · SEO 95 · 2 erreurs console"
    #[ORM\Column(type: Types::TEXT)]
    private string $summary;

    // Raw provider payload (scores, audit refs, header list...) shown on the detail view, shape is provider-specific
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $details = null;

    #[ORM\Column(name: 'checked_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $checkedAt;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getEditUrl(): ?string
    {
        return $this->editUrl;
    }

    public function setEditUrl(?string $editUrl): static
    {
        $this->editUrl = $editUrl;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(string $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    public function getDetails(): ?array
    {
        return $this->details;
    }

    public function setDetails(?array $details): static
    {
        $this->details = $details;

        return $this;
    }

    public function getCheckedAt(): \DateTimeInterface
    {
        return $this->checkedAt;
    }

    public function setCheckedAt(\DateTimeInterface $checkedAt): static
    {
        $this->checkedAt = $checkedAt;

        return $this;
    }
}
