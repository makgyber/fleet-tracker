<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;

class VehicleController extends Controller
{
    public function index()
    {
        return VehicleResource::collection(
            Vehicle::with('driver')->latest()->paginate(50)
        );
    }

    public function store(StoreVehicleRequest $request)
    {
        $vehicle = Vehicle::create($request->validated());

        return (new VehicleResource($vehicle->load('driver')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Vehicle $vehicle)
    {
        return new VehicleResource($vehicle->load('driver'));
    }

    public function update(StoreVehicleRequest $request, Vehicle $vehicle)
    {
        $vehicle->update($request->validated());

        return new VehicleResource($vehicle->load('driver'));
    }

    public function destroy(Vehicle $vehicle)
    {
        $vehicle->delete();

        return response()->noContent();
    }
}
