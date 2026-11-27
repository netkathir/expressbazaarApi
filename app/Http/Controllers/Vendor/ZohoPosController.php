<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Services\ZohoInventoryAuthService;
use App\Services\SyncLogService;
use App\Services\ZohoPosCatalogSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class ZohoPosController extends Controller
{
    public function __construct(
        private ZohoInventoryAuthService $zohoInventoryAuthService,
        private ZohoPosCatalogSyncService $zohoPosCatalogSyncService,
    ) {
    }

    public function index(): View
    {
        /** @var Vendor $vendor */
        $vendor = Auth::guard('vendor')->user();

        return view('vendor.zoho-pos.index', [
            'title' => 'Zoho POS',
            'activeMenu' => 'zoho-pos',
            'vendor' => $vendor,
            'zoho' => $vendor->zohoCredentials(),
            'connected' => $vendor->isZohoPosConnected(),
        ]);
    }

    public function sync(): RedirectResponse
    {
        /** @var Vendor $vendor */
        $vendor = Auth::guard('vendor')->user();

        if (! $vendor->isZohoPosConnected()) {
            return redirect()
                ->route('vendor.zoho-pos.index')
                ->withErrors(['zoho' => 'Connect Zoho POS before syncing.']);
        }

        try {
            $result = app(SyncLogService::class)->track([
                'vendor_id' => $vendor->id,
                'direction' => SyncLogService::INBOUND,
                'event' => SyncLogService::EVENT_CATALOG_SYNC,
            ], function ($log) use ($vendor) {
                $result = $this->zohoPosCatalogSyncService->syncExistingCatalog($vendor);

                if ($log) {
                    $log->payload = $result; // summary counts, saved when track() marks success
                }

                return $result;
            });

            return redirect()
                ->route('vendor.zoho-pos.index')
                ->with(
                    'success',
                    'Catalog synced: '.$result['imported'].' new, '.$result['updated'].' updated'
                    .', '.$result['removed'].' removed ('.$result['total'].' in POS).'
                    .(($result['deactivated'] ?? 0) > 0 ? ' '.$result['deactivated'].' older product(s) not in POS were deactivated.' : '')
                );
        } catch (Throwable $exception) {
            Log::error('Manual Zoho POS catalog sync failed.', [
                'vendor_id' => $vendor->id,
                'message' => $exception->getMessage(),
            ]);

            return redirect()
                ->route('vendor.zoho-pos.index')
                ->withErrors(['zoho' => 'Sync failed: '.$exception->getMessage()]);
        }
    }

    public function connect(Request $request): RedirectResponse
    {
        /** @var Vendor $vendor */
        $vendor = Auth::guard('vendor')->user();

        try {
            $state = bin2hex(random_bytes(16));
            $request->session()->put('zoho_oauth', [
                'vendor_id' => $vendor->id,
                'state' => $state,
            ]);

            return redirect()->away($this->zohoInventoryAuthService->authorizationUrl($vendor, $state));
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('vendor.zoho-pos.index')
                ->withErrors(['zoho' => $exception->getMessage()]);
        }
    }

    public function selectOrganization(): View|RedirectResponse
    {
        /** @var Vendor $vendor */
        $vendor = Auth::guard('vendor')->user();
        $pending = session('zoho_pending_connection');

        if (! is_array($pending) || (int) ($pending['vendor_id'] ?? 0) !== (int) $vendor->id) {
            return redirect()
                ->route('vendor.zoho-pos.index')
                ->withErrors(['zoho' => 'Zoho connection session expired. Please connect again.']);
        }

        $organizations = $pending['organizations'] ?? [];

        if (! is_array($organizations) || $organizations === []) {
            return redirect()
                ->route('vendor.zoho-pos.index')
                ->withErrors(['zoho' => 'No Zoho organizations were returned for this account.']);
        }

        return view('vendor.zoho-pos.select-organization', [
            'title' => 'Select Zoho POS Store',
            'activeMenu' => 'zoho-pos',
            'organizations' => $organizations,
        ]);
    }

    public function storeOrganization(Request $request): RedirectResponse
    {
        /** @var Vendor $vendor */
        $vendor = Auth::guard('vendor')->user();
        $pending = session('zoho_pending_connection');

        if (! is_array($pending) || (int) ($pending['vendor_id'] ?? 0) !== (int) $vendor->id) {
            return redirect()
                ->route('vendor.zoho-pos.index')
                ->withErrors(['zoho' => 'Zoho connection session expired. Please connect again.']);
        }

        $data = $request->validate([
            'organization_id' => ['required', 'string'],
        ]);

        $selected = collect($pending['organizations'] ?? [])
            ->firstWhere('organization_id', $data['organization_id']);

        if (! is_array($selected)) {
            return redirect()
                ->route('vendor.zoho-pos.select')
                ->withErrors(['organization_id' => 'Please choose a valid Zoho organization.']);
        }

        $vendor->connectZohoPos([
            'organization_id' => (string) $selected['organization_id'],
            'organization_name' => (string) ($selected['name'] ?? ''),
            'access_token' => (string) ($pending['access_token'] ?? ''),
            'refresh_token' => (string) ($pending['refresh_token'] ?? ''),
            'expires_in' => (int) ($pending['expires_in'] ?? 3600),
            'api_domain' => (string) ($pending['api_domain'] ?? config('services.zoho.api_url')),
        ]);

        $request->session()->forget(['zoho_oauth', 'zoho_pending_connection']);

        return redirect()
            ->route('vendor.zoho-pos.index')
            ->with('success', 'Zoho POS store connected successfully.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        /** @var Vendor $vendor */
        $vendor = Auth::guard('vendor')->user();
        $vendor->disconnectZohoPos();

        $request->session()->forget(['zoho_oauth', 'zoho_pending_connection']);

        return redirect()
            ->route('vendor.zoho-pos.index')
            ->with('success', 'Zoho POS disconnected.');
    }

    public function callback(Request $request): RedirectResponse
    {
        $oauth = $request->session()->get('zoho_oauth');

        if (! is_array($oauth) || empty($oauth['vendor_id']) || empty($oauth['state'])) {
            return redirect()
                ->route('vendor.login')
                ->withErrors(['email' => 'Zoho connection session expired. Please log in and connect again.']);
        }

        /** @var Vendor|null $vendor */
        $vendor = Auth::guard('vendor')->user();

        if (! $vendor || (int) $vendor->id !== (int) $oauth['vendor_id']) {
            $vendor = Vendor::query()->find($oauth['vendor_id']);

            if (! $vendor) {
                return redirect()
                    ->route('vendor.login')
                    ->withErrors(['email' => 'Vendor account for Zoho connection was not found.']);
            }

            Auth::guard('vendor')->login($vendor);
        }

        if ($request->filled('error')) {
            Log::warning('Zoho OAuth returned an error during vendor connect.', [
                'vendor_id' => $vendor->id,
                'error' => $request->string('error')->toString(),
            ]);

            return redirect()
                ->route('vendor.zoho-pos.index')
                ->withErrors(['zoho' => 'Zoho authorization was denied or failed.']);
        }

        if (! hash_equals((string) $oauth['state'], (string) $request->query('state', ''))) {
            return redirect()
                ->route('vendor.zoho-pos.index')
                ->withErrors(['zoho' => 'Invalid Zoho OAuth state. Please try connecting again.']);
        }

        $code = $request->string('code')->toString();

        if ($code === '') {
            return redirect()
                ->route('vendor.zoho-pos.index')
                ->withErrors(['zoho' => 'Authorization code is missing from the Zoho callback.']);
        }

        try {
            $tokenPayload = $this->zohoInventoryAuthService->exchangeAuthorizationCode($vendor, $code);
            $accessToken = (string) ($tokenPayload['access_token'] ?? '');

            if ($accessToken === '') {
                return redirect()
                    ->route('vendor.zoho-pos.index')
                    ->withErrors(['zoho' => 'Zoho token exchange did not return an access token.']);
            }

            $organizations = $this->zohoInventoryAuthService->listOrganizations($accessToken);

            if ($organizations === []) {
                return redirect()
                    ->route('vendor.zoho-pos.index')
                    ->withErrors(['zoho' => 'No Zoho organizations are available for this account.']);
            }

            if (count($organizations) === 1) {
                $organization = $organizations[0];
                $vendor->connectZohoPos([
                    'organization_id' => $organization['organization_id'],
                    'organization_name' => $organization['name'],
                    'access_token' => $accessToken,
                    'refresh_token' => (string) ($tokenPayload['refresh_token'] ?? ''),
                    'expires_in' => (int) ($tokenPayload['expires_in'] ?? 3600),
                    'api_domain' => (string) ($tokenPayload['api_domain'] ?? config('services.zoho.api_url')),
                ]);

                $request->session()->forget(['zoho_oauth', 'zoho_pending_connection']);

                return redirect()
                    ->route('vendor.zoho-pos.index')
                    ->with('success', 'Zoho POS store connected: '.$organization['name']);
            }

            $request->session()->put('zoho_pending_connection', [
                'vendor_id' => $vendor->id,
                'access_token' => $accessToken,
                'refresh_token' => (string) ($tokenPayload['refresh_token'] ?? ''),
                'expires_in' => (int) ($tokenPayload['expires_in'] ?? 3600),
                'api_domain' => (string) ($tokenPayload['api_domain'] ?? config('services.zoho.api_url')),
                'organizations' => $organizations,
            ]);
            $request->session()->forget('zoho_oauth');

            return redirect()->route('vendor.zoho-pos.select');
        } catch (RuntimeException $exception) {
            Log::error('Zoho vendor connect failed.', [
                'vendor_id' => $vendor->id,
                'message' => $exception->getMessage(),
            ]);

            return redirect()
                ->route('vendor.zoho-pos.index')
                ->withErrors(['zoho' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            Log::error('Unexpected Zoho vendor connect failure.', [
                'vendor_id' => $vendor->id,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return redirect()
                ->route('vendor.zoho-pos.index')
                ->withErrors(['zoho' => 'An unexpected error occurred while connecting Zoho POS.']);
        }
    }
}
