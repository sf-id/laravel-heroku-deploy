<?php

namespace CodeGreenCreative\LaravelHerokuDeploy\Console\Commands;

use CodeGreenCreative\LaravelHerokuDeploy\Exceptions\LaravelHerokuDeployException;
use CodeGreenCreative\LaravelHerokuDeploy\Traits\ConcernsHerokuReviewApps;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class Postdeploy extends Command
{
    use ConcernsHerokuReviewApps;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'heroku:postdeploy';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add domains to Heroku and update Cloudflare DNS after a review app is created.';

    /**
     * Execute the console command.
     *
     * @see https://medium.com/clutter-engineering/heroku-review-apps-with-custom-domains-8edfc0a2b153
     * @return int
     */
    public function handle()
    {
        try {
            // Collect cnames to add to Cloudflare
            $host_cnames = new Collection;
            // Get ALL zones
            $response = $this->cloudflare('get', 'zones');
            $zones = collect($response->json()['result']);
            // Loop through each of the zones we need to modify
            foreach ($this->cloudflare_zones as $domain => $subdomains) {
                // Find zone record
                $zone = $zones->firstWhere('name', $domain);
                // Each subdomain needs to be added
                foreach ($subdomains as $subdomain) {
                    // Add hostname to Heroku review app
                    $response = $this->heroku('post', sprintf('apps/%s/domains', $this->heroku_app_name), [
                        'hostname' => $this->getHostname($subdomain, $domain)
                    ]);
                    // Push cnames on to the collection
                    $host_cnames->push([
                        'zone' => $zone['id'],
                        'hostname' => $this->getHostname($subdomain, $domain),
                        'cname' => $response->json()['cname']
                    ]);
                }
            }
            // Loop through the domains added into heroku
            foreach ($host_cnames as $host_cname) {
                $response = $this->cloudflare('post', sprintf('zones/%s/dns_records', $host_cname['zone']), [
                    'type' => 'CNAME',
                    'name' => $host_cname['hostname'],
                    'content' => $host_cname['cname']
                ]);
            };
            // Update config vars to support the review app
            $response = $this->heroku('patch', sprintf('apps/%s/config-vars', $this->heroku_app_name), [
                'APP_BASE_DOMAIN' => sprintf('pr-%s.%s', $this->heroku_pr_number, $this->primary_domain),
                'APP_URL' => sprintf('https://pr-%s.%s', $this->heroku_pr_number, $this->primary_domain),
                'SESSION_SECURE_COOKIE' => $this->enable_acm ? 'true' : 'false',
                'SESSION_COOKIE' => sprintf('PR%s_SID', $this->heroku_pr_number)
            ]);
            // Add any Heroku addons
            foreach ($this->heroku_addon_attachments as $addon_id => $app_id) {
                // Attach staging postgres database to work with review apps
                $response = $this->heroku('post', 'addon-attachments', [
                    'addon' => $addon_id, // This could change
                    'app' => $this->heroku_app_name,
                    'confirm' => $app_id
                ]);
            }
            // Enable ACM (SSL) NOT IMPLEMENTING DUE TO LETS ENCRYPT RATE LIMITING
            $response = $this->heroku('post', sprintf('apps/%s/acm', $this->heroku_app_name), [], [
                'Content-Type' => 'application/json',
            ]);
        } catch (LaravelHerokuDeployException $e) {
            // Report handled exception to bugsnag if installed
            if (class_exists(\Bugsnag\BugsnagLaravel\Facades\Bugsnag::class)) {
                \Bugsnag\BugsnagLaravel\Facades\Bugsnag::notifyException($this);
            }
            // Should any of the above requests fail, the build will fail requiring a rebuild
            return 1;
        }
        return 0;
    }
}
