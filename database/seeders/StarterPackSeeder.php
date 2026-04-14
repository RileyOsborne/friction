<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Topic;
use App\Models\Answer;
use Illuminate\Database\Seeder;

class StarterPackSeeder extends Seeder
{
    /**
     * Seed the starter pack categories.
     */
    public function run(): void
    {
        $data = require database_path('data/starter-pack.php');

        foreach ($data['categories'] as $catData) {
            // Find or create topic (topics are shared)
            $topicId = null;
            if (!empty($catData['topic'])) {
                $topic = Topic::withTrashed()->updateOrCreate(
                    ['name' => $catData['topic']],
                    ['deleted_at' => null]
                );
                $topicId = $topic->id;
            }

            // Create or restore category
            $category = Category::withTrashed()->updateOrCreate(
                ['title' => $catData['title']],
                [
                    'description' => $catData['description'] ?? null,
                    'topic_id' => $topicId,
                    'is_starter' => true,
                    'deleted_at' => null,
                ]
            );

            // Sync answers
            // We'll delete existing answers and recreate them to ensure they match the starter pack
            $category->answers()->delete();

            foreach ($catData['answers'] as $index => $answerData) {
                $position = $index + 1;
                $isFriction = $position > 10;
                
                Answer::create([
                    'category_id' => $category->id,
                    'text' => $answerData['text'],
                    'stat' => $answerData['stat'] ?? null,
                    'position' => $position,
                    'is_friction' => $isFriction,
                    'points' => $isFriction ? -5 : $position,
                ]);
            }
        }
    }
}
