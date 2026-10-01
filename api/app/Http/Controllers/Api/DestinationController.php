<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDestinationRequest;
use App\Http\Resources\DestinationResource;
use App\Models\Destination;
use Illuminate\Http\Request;

class DestinationController extends Controller
{
    public function index(Request $request)
    {
        $query = Destination::query();

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('address', 'like', "%{$search}%");
        }

        return DestinationResource::collection($query->latest()->paginate(50));
    }

    public function store(StoreDestinationRequest $request)
    {
        $destination = Destination::create($request->validated());

        return (new DestinationResource($destination))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Destination $destination)
    {
        return new DestinationResource($destination);
    }

    public function update(StoreDestinationRequest $request, Destination $destination)
    {
        $destination->update($request->validated());

        return new DestinationResource($destination);
    }

    public function destroy(Destination $destination)
    {
        $destination->delete();

        return response()->noContent();
    }
}
