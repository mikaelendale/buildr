<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;

class CuratedTagSeeder extends Seeder
{
    /**
     * Curated tags shown as chips on the landing page (plan §9.1).
     */
    private const TAGS = [
        'ai' => ['AI', 'Builders working with models, agents and everything machine learning.'],
        'design' => ['Design', 'Product, UI and visual designers sharing their craft.'],
        'web-dev' => ['Web Dev', 'Frontend, backend and full-stack web developers.'],
        'mobile' => ['Mobile', 'iOS, Android and cross-platform app builders.'],
        'startups' => ['Startups', 'Founders building companies in public.'],
        'students' => ['Students', 'Students learning and shipping as they go.'],
        'crypto' => ['Crypto', 'Web3, blockchain and crypto builders.'],
        'content' => ['Content', 'Writers, video makers and educators.'],
        'open-source' => ['Open Source', 'Maintainers and contributors working in the open.'],
        'freelance' => ['Freelance', 'Independent builders working with clients.'],
        'data' => ['Data', 'Data science, analytics and data engineering.'],
        'devops' => ['DevOps', 'Infrastructure, cloud and the systems that keep things running.'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sort = 0;

        foreach (self::TAGS as $slug => [$name, $description]) {
            Tag::updateOrCreate(['slug' => $slug], [
                'name' => $name,
                'description' => $description,
                'is_curated' => true,
                'sort' => $sort++,
            ]);
        }
    }
}
