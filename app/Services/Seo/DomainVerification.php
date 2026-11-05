<?php

namespace App\Services\Seo;

use App\Models\Project;

class DomainVerification
{
    public function matches(Project $project): bool
    {
        $origin = $project->seo_settings['public_url'] ?? null;
        if (!$origin || !$project->seo_domain_token) {
            return false;
        }
        try {
            $records = $this->records('_blogsaas-verification.'.parse_url($origin, PHP_URL_HOST));
        } catch (\Throwable) {
            return false;
        }
        foreach ($records ?: [] as $record) {
            $value = $record['txt'] ?? implode('', $record['entries'] ?? []);
            if (hash_equals('blogsaas='.$project->seo_domain_token, $value)) {
                return true;
            }
        }
        return false;
    }

    protected function records(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);
        return is_array($records) ? $records : [];
    }
}
