<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Controller\Management;

use c975L\ConfigBundle\Command\MessengerCleanupCommand;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\MessengerFailedMessageService;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

class MessengerFailedController extends AbstractController
{
    // EasyAdmin prefixes this with the Dashboard's own route name, giving management_XXX
    public const PURGE_ROUTE = 'management_config_messenger_failed_purge';
    public const RETRY_ROUTE = 'management_config_messenger_failed_retry';
    public const DELETE_ROUTE = 'management_config_messenger_failed_delete';
    public const DELETE_GROUP_ROUTE = 'management_config_messenger_failed_delete_group';

    public function __construct(
        private readonly MessengerFailedMessageService $messengerFailedMessageService,
        private readonly MessengerCleanupCommand $messengerCleanupCommand,
        private readonly ConfigServiceInterface $configService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // Shows the failed Messenger messages in a readable form to ROLE_SUPER_ADMIN, grouped by error so recurring issues can be spotted and bulk-deleted; ROLE_ADMIN only sees a reassuring "already signaled" message, no technical detail
    #[AdminRoute(
        path: '/messenger-failed',
        name: 'config_messenger_failed',
    )]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-admin'));

        $messages = $this->messengerFailedMessageService->findAll();

        return $this->render('@c975LConfig/management/messenger_failed_index.html.twig', [
            'messages' => $messages,
            'groups' => $this->messengerFailedMessageService->groupByError($messages),
        ]);
    }

    // Runs the cleanup command immediately (purge + digest email if new important failures exist)
    #[AdminRoute(
        path: '/messenger-failed/purge',
        name: 'config_messenger_failed_purge',
        options: ['methods' => ['POST']]
    )]
    public function purgeNow(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ($this->isCsrfTokenValid(self::PURGE_ROUTE, $request->request->get('_token'))) {
            $stats = $this->messengerCleanupCommand->cleanup();
            $this->addFlash('success', $this->translator->trans(
                'flash.messenger_purged',
                ['%count%' => $stats['purged']],
                'config',
            ));
        }

        return $this->redirectToRoute('management_config_messenger_failed');
    }

    // Replays a single failed message right away; reports success or the new error so the admin can decide whether it's worth retrying again or just deleting
    #[AdminRoute(
        path: '/messenger-failed/{id}/retry',
        name: 'config_messenger_failed_retry',
        options: ['methods' => ['POST']]
    )]
    public function retry(int $id, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ($this->isCsrfTokenValid(self::RETRY_ROUTE, $request->request->get('_token'))) {
            $result = $this->messengerFailedMessageService->retry($id);

            if (!$result['found']) {
                $this->addFlash('warning', $this->translator->trans('flash.messenger_not_found', ['%id%' => $id], 'config'));
            } elseif ($result['success']) {
                $this->addFlash('success', $this->translator->trans('flash.messenger_retry_success', ['%id%' => $id], 'config'));
            } else {
                $this->addFlash('danger', $this->translator->trans(
                    'flash.messenger_retry_failed',
                    ['%id%' => $id, '%error%' => $result['error'] ?? '?'],
                    'config',
                ));
            }
        }

        return $this->redirectToRoute('management_config_messenger_failed');
    }

    // Deletes a single failed message without retrying it
    #[AdminRoute(
        path: '/messenger-failed/{id}/delete',
        name: 'config_messenger_failed_delete',
        options: ['methods' => ['POST']]
    )]
    public function delete(int $id, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ($this->isCsrfTokenValid(self::DELETE_ROUTE, $request->request->get('_token'))) {
            $this->messengerFailedMessageService->deleteById($id);
            $this->addFlash('success', $this->translator->trans('flash.messenger_deleted', ['%id%' => $id], 'config'));
        }

        return $this->redirectToRoute('management_config_messenger_failed');
    }

    // Deletes every message sharing the same error at once (see index()'s error groups)
    #[AdminRoute(
        path: '/messenger-failed/delete-group',
        name: 'config_messenger_failed_delete_group',
        options: ['methods' => ['POST']]
    )]
    public function deleteGroup(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ($this->isCsrfTokenValid(self::DELETE_GROUP_ROUTE, $request->request->get('_token'))) {
            $ids = array_map('intval', $request->request->all('ids'));
            $count = $this->messengerFailedMessageService->deleteByIds($ids);
            $this->addFlash('success', $this->translator->trans('flash.messenger_group_deleted', ['%count%' => $count], 'config'));
        }

        return $this->redirectToRoute('management_config_messenger_failed');
    }
}
