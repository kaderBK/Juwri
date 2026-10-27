<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Marketing\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Marketing\Models\AbExperiment;

class AbExperimentController extends Controller
{
    public function index()
    {
        $experiments = AbExperiment::orderByDesc('id')->get();

        return view('admin.ab_experiments.index', compact('experiments'));
    }

    public function create()
    {
        return view('admin.ab_experiments.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|alpha_dash|max:80|unique:ab_experiments,name',
            'description' => 'nullable|string|max:255',
            'variants' => 'required|string',
        ]);

        $variants = array_values(array_filter(array_map(
            fn ($v) => trim($v),
            explode(',', $request->get('variants'))
        )));

        if (count($variants) < 2) {
            return back()->withErrors(['variants' => 'At least 2 variants required (comma-separated).'])->withInput();
        }

        AbExperiment::create([
            'name' => $request->get('name'),
            'description' => $request->get('description'),
            'variants' => $variants,
            'status' => 'active',
            'started_at' => now(),
        ]);

        app_alert_success('Experiment created.');

        return redirect()->route('admin.ab-experiments.index');
    }

    public function destroy(AbExperiment $abExperiment)
    {
        $abExperiment->update(['status' => 'ended', 'ended_at' => now()]);
        app_alert_success('Experiment ended.');

        return back();
    }
}
