<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDriverRequest;
use App\Http\Resources\DriverResource;
use App\Models\Driver;

class DriverController extends Controller
{
    public function index()
    {
        return DriverResource::collection(
            Driver::with('vehicles')->latest()->paginate(50)
        );
    }

    public function store(StoreDriverRequest $request)
    {
        $driver = Driver::create($request->validated());

        return (new DriverResource($driver))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Driver $driver)
    {
        return new DriverResource($driver->load('vehicles'));
    }

    public function update(StoreDriverRequest $request, Driver $driver)
    {
        $driver->update($request->validated());

        return new DriverResource($driver);
    }

    public function destroy(Driver $driver)
    {
        $driver->delete();

        return response()->noContent();
    }
}
