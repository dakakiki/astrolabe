<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Clients\SaveBirthDetails;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveBirthDetailsRequest;
use App\Http\Resources\BirthDetailsResource;
use App\Models\Client;

class ClientBirthDetailsController extends Controller
{
    public function update(SaveBirthDetailsRequest $request, Client $client, SaveBirthDetails $saveBirthDetails): BirthDetailsResource
    {
        $details = $saveBirthDetails->handle($client, $request->validated());
        $client->touchActivity();

        return BirthDetailsResource::make($details);
    }
}
