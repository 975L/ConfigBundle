<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Controller\Management;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

class ManagementShortcutController extends AbstractController
{
    // Plain Symfony route (not #[AdminRoute], which always nests under the dashboard's own path) - a short, easy-to-type alias for /management; access itself stays gated by DashboardController::index()'s own denyAccessUnlessGranted()
    #[Route('/m', name: 'management_shortcut', methods: ['GET'])]
    public function index(): RedirectResponse
    {
        return $this->redirectToRoute('management');
    }
}
