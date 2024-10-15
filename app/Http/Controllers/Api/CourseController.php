<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

class CourseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = Storage::disk('local')->get('courses.json');
        $courses = json_decode($data, true);

        $validation = validator()->make($request->all(), [
            'month' => 'nullable|date_format:Y-m',
            'venue' => 'nullable|string',
            'type' => 'nullable|string',
        ]);

        if ($validation->fails()) {
            return response()->json([
                'message' => 'Invalid parameters',
                'errors' => $validation->errors(),
            ], status: HttpFoundationResponse::HTTP_BAD_REQUEST);
        }

        $filteredCourses = collect($courses)
            ->filter(function ($course) use ($request) {
                if ($request->has('month')) {
                    $month = $request->get('month');
                    $formattedStartDate = $course['formatted_start_date']; // Sat 12th October 2024

                    $date = \DateTime::createFromFormat('D jS F Y', $formattedStartDate);
                    $formattedMonth = $date->format('Y-m');

                    if ($formattedMonth !== $month) {
                        return false;
                    }
                }

                if ($request->has('venue')) {
                    $venue = $request->get('venue');
                    if ($course['venue']['name'] !== $venue) {
                        return false;
                    }
                }

                if ($request->has('type')) {
                    $type = $request->get('type');

                    $startDates = collect($course['days'])->pluck('start_date');
                    $courseDayNames = $startDates->map(function ($startDate) {
                        return Carbon::parse($startDate)->format('l');
                    });

                    if ($type === 'Monday to Friday') {
                        $weekdays = collect(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']);

                        return $weekdays->diff($courseDayNames)->isEmpty();
                    } else if ($type === 'Day Release') {
                        $spansMultipleWeeks = $startDates->map(function ($startDate) {
                            return Carbon::parse($startDate)->format('W');
                        })->unique()->count() > 1;

                        return $courseDayNames->count() === 1 && !$spansMultipleWeeks;
                    } else if ($type === 'Weekend') {
                        $weekendDays = collect(['Saturday', 'Sunday']);

                        $isWeekend = $courseDayNames->every(function ($day) use ($weekendDays) {
                            return $weekendDays->contains($day);
                        });

                        return $isWeekend;
                    }
                }

                return true;
            });

        $mergedCourses = $filteredCourses->groupBy(function ($course) {
            return $course['venue']['name'] . $course['formatted_start_date'] . $course['formatted_end_date'];
        })->map(function ($courses) {
            $firstCourse = $courses->first();

            $totalAvailableSpaces = $courses->sum('available_spaces');

            return array_merge($firstCourse, [
                'available_spaces' => $totalAvailableSpaces,
            ]);
        })->values();

        return response()->json($mergedCourses);
    }
}
