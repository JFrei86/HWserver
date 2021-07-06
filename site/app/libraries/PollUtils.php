<?php

namespace app\libraries;

use app\libraries\Core;
use app\libraries\FileUtils;

/**
 * Class FileUtils
 *
 * Contains various useful functions for the polling system.
 */
class PollUtils {
    /**
     * Generates a PHP array containing the all the poll data used in the export procedure
     * @param \app\entities\poll\Poll[] $polls the array of polls to be summarized
     */
    public static function getPollExportData(array $polls): array {
        $export_data = [];
        foreach ($polls as $poll) {
            $poll_data = [
                'id' => $poll->getId(),
                'name' => $poll->getName(),
                'question' => $poll->getQuestion(),
                'question_type' => $poll->getQuestionType(),
                'responses' => [],
                'correct_responses' => [],
                'release_date' => $poll->getReleaseDate()->format('Y-m-d'),
                'status' => $poll->getStatus(),
                'image_path' => $poll->getImagePath(),
            ];
            foreach ($poll->getOptions() as $option) {
                $poll_data['responses'][$option->getId()] = $option->getResponse();
                if ($option->isCorrect()) {
                    $poll_data['correct_responses'][] = $option->getId();
                }
            }
            $export_data[] = $poll_data;
        }
        return $export_data;
    }

    public static function getPollTypes(): array {
        return [
            "single-response-single-correct",
            "single-response-multiple-correct",
            "single-response-survey",
            "multiple-response-exact",
            "multiple-response-flexible",
            "multiple-response-survey"
        ];
    }

    public static function isSingleResponse(string $poll_type): bool {
        return (($poll_type == "single-response-single-correct")
                || ($poll_type == "single-response-multiple-correct")
                || ($poll_type == "single-response-survey"));
    }
}
