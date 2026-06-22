<?php

namespace c975L\ConfigBundle\Management;

interface MenuProviderInterface
{
    public function getSection(): array;

    public function getMenu(): array;

    public function getMenuItems(): iterable;
}