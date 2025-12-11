<?php

use App\Models\YnabUser;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Route;
use YnabSdkLaravel\YnabSdkLaravel\OauthHelper;
use YnabSdkLaravel\YnabSdkLaravel\Ynab;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::ynabSdkLaravelOauth();

$dataTypes = [
    'accounts',
    'payees',
    'payee_locations',
    'category_groups',
    'categories',
    'months',
    'transactions',
    'subtransactions',
    'scheduled_transactions',
    'scheduled_subtransactions',
];

Route::get('/ynab', function (Request $request) use ($dataTypes) {
    $timezone = $request->get('timezone') ?? config('app.timezone');

    $expiresIn = Cookie::get('ynab_expires_in');
    $dateRetrieved = Carbon::parse(Cookie::get('ynab_date_retrieved'));

    $data = [
        'ynabAuthUrl' => OauthHelper::getAuthUrl(),
        'dateRetrieved' => $dateRetrieved
            ->timezone($timezone)
            ->format('m/d/Y h:i:s A T'),
        'expiresIn' => $expiresIn,
        'ynabDataTypes' => $dataTypes,
    ];

    if ($expiresIn && $dateRetrieved) {
        $expirationTimeOfAccessToken = OauthHelper::getExpirationTimeOfAccessToken(
            $dateRetrieved,
            $expiresIn
        );

        $data['expirationTimeOfAccessToken'] = $expirationTimeOfAccessToken;

        $data['seconds'] = round(now()->diffInSeconds($expirationTimeOfAccessToken), 0);

        $data['hasUnexpiredToken'] = $data['seconds'] > 0;
    }

    $currentYnabUserId = Cookie::get('current_ynab_user');

    if ($currentYnabUserId) {
        $data['currentYnabUser'] = YnabUser::find($currentYnabUserId);

        $data['ynabData'] = Cache::get('ynabData_'.$currentYnabUserId, []);

        $data['defaultBudget'] = $data['ynabData']['budgetsData']['data']['default_budget']['id'] ?? null;
    }

    return view('ynab', $data);
})->name('ynab');

Route::post('/ynab/fetch', function (Request $request) {
    $accessToken = $request->cookie('ynab_access_token');

    $ynab = new Ynab($accessToken);

    $userInfoResponse = $ynab->user()->get();

    if ($userInfoResponse->failed()) {
        return to_route('ynab')->with('error', 'Failed to fetch user info.');
    }

    $userId = $userInfoResponse->json('data.user.id');

    YnabUser::updateOrCreate([
        'id' => $userId,
    ]);

    Cookie::queue('current_ynab_user', $userId);

    $budgetListResponse = $ynab->budgets()->list();

    if ($budgetListResponse->failed()) {
        return to_route('ynab')->with('error', 'Failed to fetch budgets.');
    }

    $budgetsData = $budgetListResponse->json();

    $budgets = $budgetListResponse->json('data.budgets', []);

    $serverKnowledge = $budgetListResponse->json('data.server_knowledge');

    $detailedBudgetsData = [];

    foreach ($budgets as $budget) {
        $id = $budget['id'] ?? null;

        if (! $id) {
            continue;
        }

        $budgetGetResponse = $ynab->budgets()->get($id, $serverKnowledge);

        if ($budgetGetResponse->failed()) {
            continue;
        }

        $detailedBudgetsData[$id] = $budgetGetResponse->json();
    }

    Cache::put(
        'ynabData_'.$userId,
        [
            'budgetsData' => $budgetsData,
            'detailedBudgetsData' => $detailedBudgetsData,
        ]
    );

    return to_route('ynab')
        ->with('success', 'Budgets fetched successfully.');
})->name('ynab.fetch');

Route::get('ynab/{budget}/transactions', function ($budget) {
    $ynabData = Cache::get('ynabData_'.Cookie::get('current_ynab_user'), []);

    $transactions = $ynabData['detailedBudgetsData'][$budget]['data']['budget']['transactions'] ?? [];

    return response()->json($transactions, 200, [], JSON_PRETTY_PRINT);
})->name('ynab.transactions');

foreach ($dataTypes as $dataType) {
    Route::get("ynab/{budget}/$dataType", function ($budget) use ($dataType) {
        $ynabData = Cache::get('ynabData_'.Cookie::get('current_ynab_user'), []);

        $data = $ynabData['detailedBudgetsData'][$budget]['data']['budget'][$dataType] ?? [];

        return response()->json($data, 200, [], JSON_PRETTY_PRINT);
    })->name("ynab.$dataType");
}
