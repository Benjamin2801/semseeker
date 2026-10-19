<?php

namespace App\Suggest\Source;

final class GoogleSource extends AbstractGoogleSuggestSource
{
    public function getName(): string
    {
        return 'google';
    }

    public function getAcronym(): string
    {
        return 'GOO';
    }

    protected function getDataset(): ?string
    {
        return null;
    }
}
