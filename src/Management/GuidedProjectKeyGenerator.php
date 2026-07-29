<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Derives the localStorage key the guided-project panel stores its progress under (see assets/js/guided-project.js). A project being a replayable exercise rather than a record, its progress isn't worth a table - but two admins sharing one browser profile would then share one key, hence this per-user scoping. Never by the user identifier itself: that's usually an email, and a localStorage key outlives the session, so the next person on a shared machine would read the previous admin's address straight from the devtools. HMAC rather than a plain digest, an email living in a space small enough to be brute-forced back from one - keyed with the application secret, the result gives nothing away
class GuidedProjectKeyGenerator
{
    private const LENGTH = 16;

    public function __construct(
        private readonly Security $security,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    // 16 hex chars (64 bits), far beyond what telling a site's handful of admins apart needs - an empty string when nobody is logged in, the panel then storing nothing at all (see GuidedProjectMountBuilder)
    public function getKey(): string
    {
        $user = $this->security->getUser();
        if (null === $user) {
            return '';
        }

        return substr(hash_hmac('sha256', $user->getUserIdentifier(), $this->secret), 0, self::LENGTH);
    }
}
