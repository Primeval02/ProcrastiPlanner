<?php
require_once('globalTools.php');
require_once('validation.php');

// FUNCIONAL!!! - ISSUES: OVERLAP WITH ASSIGNMENT START TIMES, BUT INSERTS 
// INTO GAP SPACE PROPERLY AND SORTS PROPERLY

/* OUTLINE:
 * Fetch all events and assignments associated with userId
 * All assignments will be processed to find their A value
 * Event start times will be STATIC
 * Sort assignments hi-lo
 * calculate Event2 - Event1 start time - end time for Gap1, so on so forth
 * Insert assignments from array in order into gaps + 30min pad
 * Update start times for the assignments; end times will adjust: + time allotted
 * New schedule should be generated, array returned
 */


// CONFIRMED WORKING VIA INSOMNIA //
// CHANGED eventDate key to startTime to be consistent
function fetchEvents($userId) {
    $db = getConnection();
    $query = "SELECT * FROM event WHERE userId=?";
    $stmt = mysqli_prepare($db, $query);
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $events = array();

    while ($event = mysqli_fetch_assoc($result)) {
        $event['startTime'] = $event['eventDate'];
        unset($event['eventDate']); // Remove eventDate
        $events[] = $event;
    }

    mysqli_free_result($result);
    return $events;
}

// WORKS VIA INSOMNIA SORTS HI - LO BASED ON aValue (Priority comp.)
function sortAssignments($userId) {
    $db = getConnection();
    $query = "SELECT * FROM assignment WHERE userId=?";
    $stmt = mysqli_prepare($db, $query);
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $assignments = array();

    while ($assignment = mysqli_fetch_assoc($result)) {
        // Compute aValue for each assignment
        $timeParts = explode(':', $assignment['time']);
        $timeTakenMinutes = ($timeParts[0] * 60) + $timeParts[1] + ($timeParts[2] / 60);
        $aValue = $timeTakenMinutes / 60 + $assignment['priority'];
        
        // Add aValue to the assignment
        $assignment['aValue'] = $aValue;

        // Echo assignment details with aValue
        //echo "Assignment: " . $assignment['name'] . ", aValue: " . $aValue . "<br>";

        $assignments[] = $assignment;
    }

    // Sort assignments based on aValue
    usort($assignments, function($a, $b) {
        return $b['aValue'] - $a['aValue'];
    });

    mysqli_free_result($result);
    return $assignments;
}

// WORKS CONFIRMED ON INSOMNIA //
function calculateGaps($schedule) {
    // Extract events and assignments from the schedule
    $events = array_filter($schedule, function($item) {
        return isset($item['startTime']);
    });

    $assignments = array_filter($schedule, function($item) {
        return isset($item['startTime']);
    });

    // Sort events by startTime
    usort($events, function($a, $b) {
        return strtotime($a['startTime']) - strtotime($b['startTime']);
    });

    // Sort assignments by start time
    usort($assignments, function($a, $b) {
        return strtotime($a['startTime']) - strtotime($b['startTime']);
    });

    $gaps = array();

    // Calculate gaps between consecutive events
    for ($i = 1; $i < count($events); $i++) {
        $endTimePrev = strtotime($events[$i - 1]['startTime']);
        $startTimeCurr = strtotime($events[$i]['startTime']);
        
        // Calculate the gap start time and length
        $gapStartTime = date('Y-m-d H:i:s', $endTimePrev);
        $gapLength = round(($startTimeCurr - $endTimePrev) / 60); // in minutes

        // Add the gap to the gaps array
        $gaps[] = array('startTime' => $gapStartTime, 'length' => $gapLength);
    }

    return $gaps;
}

// WORKS, HELPS SORT FINAL SCHEDULE CHRONOLOGICALLY //
function sortSchedule(&$schedule) {
    usort($schedule, function($a, $b) {
        // Check if both items are assignments
        if (isset($a['startTime']) && isset($b['startTime'])) {
            // Compare assignments by startTime
            return strtotime($a['startTime']) - strtotime($b['startTime']);
        }
        
        // Check if both items are events
        if (isset($a['startTime']) && isset($b['startTime'])) {
            // Compare events by startTime
            return strtotime($a['startTime']) - strtotime($b['startTime']);
        }
        
        // If one item is an assignment and the other is an event, prioritize events
        if (isset($a['startTime'])) {
            return -1; // $a is an event, so it comes first
        } else {
            return 1; // $b is an event, so it comes first
        }
    });
}

// WORKS SEE FINISHED SCHEDULE //
function insertAssignmentsIntoGaps(&$assignments, &$gaps, &$schedule) {
    // Sort assignments based on aValue in descending order
    usort($assignments, function($a, $b) {
        return $b['aValue'] - $a['aValue']; // Sort in descending order
    });

    // Loop through assignments
    foreach ($assignments as &$assignment) {
        // Check if there are any remaining gaps
        if (empty($gaps)) {
            break; // No more gaps to insert assignments
        }

        // Get the largest length gap
        $largestGapIndex = 0;
        $largestGapLength = $gaps[0]['length'];

        for ($i = 1; $i < count($gaps); $i++) {
            if ($gaps[$i]['length'] > $largestGapLength) {
                $largestGapIndex = $i;
                $largestGapLength = $gaps[$i]['length'];
            }
        }

        // Assign the start time of the assignment to the start time of the largest length gap
        $assignment['startTime'] = $gaps[$largestGapIndex]['startTime'];

        $assignmentStartTime = strtotime($assignment['startTime']);
        foreach ($schedule as $item) {
            if (isset($item['startTime']) && isset($item['endTime'])) {
                $eventEndTime = strtotime($item['endTime']);
                if ($assignmentStartTime < $eventEndTime) {
                    // Adjust assignment start time to be after event end time
                    $assignmentStartTime = $eventEndTime;
                }
            }
        }

        // Add padding of 30 minutes plus the time of the assignment
        $assignmentStartTime += 1800; // 30 minutes in seconds
        $assignmentStartTime += strtotime($assignment['time']) - strtotime('00:00:00');
        $assignment['startTime'] = date('Y-m-d H:i:s', $assignmentStartTime);

        // Remove the largest length gap from the gaps array
        array_splice($gaps, $largestGapIndex, 1);

        // Adjust end time of assignment if needed
        $endTime = date('Y-m-d H:i:s', strtotime($assignment['startTime']) + strtotime($assignment['time']) - strtotime('00:00:00'));
        $assignment['endTime'] = $endTime;

        // Add the assignment to the schedule
        $schedule[] = $assignment;

        unset($assignment);
    }
}

// "MAIN" ADDS START AND END OF DAY TOO //
function generateSchedule($userId) {
    $schedule = fetchEvents($userId);
    $assignments = sortAssignments($userId);

    // To mark the start and end of the day
    $schedule[] = array(
        'id' => -1,
        'name' => 'Day Start',
        'duration' => '00:00:00',
        'startTime' => '2024-04-18 08:00:00',
        'userId' => $userId,
        'complete' => 0
    );
    
    $schedule[] = array(
        'id' => -2,
        'name' => 'Day End',
        'duration' => '00:00:00',
        'startTime' => '2024-04-18 22:00:00',
        'userId' => $userId,
        'complete' => 0
    );

    //echo "Fetched Events: " . json_encode($events);
    //echo "Fetched & Sorted Assignments: " . json_encode($assignments);

    $gaps = calculateGaps($schedule);
    //echo "Gap Array: " . json_encode($gaps);

    insertAssignmentsIntoGaps($assignments, $gaps, $schedule);

    sortSchedule($schedule);

    //echo "Final schedule: ";
    return $schedule; 
}

