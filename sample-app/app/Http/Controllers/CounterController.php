<?php

namespace App\Http\Controllers;

use App\Models\Counter;

class CounterController extends Controller
{
    public function add()
    {
        $counter = new Counter();
        $counter->count = 1;
        $counter->save();
        $value = Counter::sum('count');
        return response()->json(["value" => $value], 200);
    }

    public function get()
    {
        $value = Counter::sum('count');
        return response()->json(["value" => $value], 200);
    }

    public function stats()
    {
        return response()->json([
            "total"    => (int) Counter::sum('count'),
            "today"    => (int) Counter::whereDate('created_at', today())->sum('count'),
            "first_at" => Counter::min('created_at'),
            "last_at"  => Counter::max('created_at'),
        ], 200);
    }
}
