<?php
// Set JSON response header
header('Content-Type: application/json');

// Include database connection and auth helper
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/auth.php';

// Require logged-in landlord role
requireRole('landlord');

// Always identify landlord using $_SESSION['user_id']
$landlordId = getCurrentUserId();

try {
    // 1. Total Properties
    $stmt1 = $conn->prepare("SELECT COUNT(*) FROM properties WHERE landlord_id = ?");
    $stmt1->bind_param("i", $landlordId);
    $stmt1->execute();
    $res1 = $stmt1->get_result();
    $row1 = $res1 ? $res1->fetch_row() : [0];
    $totalProperties = (int)($row1[0] ?? 0);

    // 2. Rented Properties
    $stmt2 = $conn->prepare("SELECT COUNT(*) FROM properties WHERE landlord_id = ? AND status = 'rented'");
    $stmt2->bind_param("i", $landlordId);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    $row2 = $res2 ? $res2->fetch_row() : [0];
    $rentedProperties = (int)($row2[0] ?? 0);

    // 3. Available Properties
    $stmt3 = $conn->prepare("SELECT COUNT(*) FROM properties WHERE landlord_id = ? AND status = 'available'");
    $stmt3->bind_param("i", $landlordId);
    $stmt3->execute();
    $res3 = $stmt3->get_result();
    $row3 = $res3 ? $res3->fetch_row() : [0];
    $availableProperties = (int)($row3[0] ?? 0);

    // 4. Total Applications
    $stmt4 = $conn->prepare("SELECT COUNT(*) FROM rental_applications ra JOIN properties p ON ra.property_id = p.property_id WHERE p.landlord_id = ?");
    $stmt4->bind_param("i", $landlordId);
    $stmt4->execute();
    $res4 = $stmt4->get_result();
    $row4 = $res4 ? $res4->fetch_row() : [0];
    $totalApplications = (int)($row4[0] ?? 0);

    // 5. Pending Applications
    $stmt5 = $conn->prepare("SELECT COUNT(*) FROM rental_applications ra JOIN properties p ON ra.property_id = p.property_id WHERE p.landlord_id = ? AND ra.status = 'pending'");
    $stmt5->bind_param("i", $landlordId);
    $stmt5->execute();
    $res5 = $stmt5->get_result();
    $row5 = $res5 ? $res5->fetch_row() : [0];
    $pendingApplications = (int)($row5[0] ?? 0);

    // 6. Approved Applications
    $stmt6 = $conn->prepare("SELECT COUNT(*) FROM rental_applications ra JOIN properties p ON ra.property_id = p.property_id WHERE p.landlord_id = ? AND ra.status = 'approved'");
    $stmt6->bind_param("i", $landlordId);
    $stmt6->execute();
    $res6 = $stmt6->get_result();
    $row6 = $res6 ? $res6->fetch_row() : [0];
    $approvedApplications = (int)($row6[0] ?? 0);

    // 7. Rejected Applications
    $stmt7 = $conn->prepare("SELECT COUNT(*) FROM rental_applications ra JOIN properties p ON ra.property_id = p.property_id WHERE p.landlord_id = ? AND ra.status = 'rejected'");
    $stmt7->bind_param("i", $landlordId);
    $stmt7->execute();
    $res7 = $stmt7->get_result();
    $row7 = $res7 ? $res7->fetch_row() : [0];
    $rejectedApplications = (int)($row7[0] ?? 0);

    // 8. Active Agreements
    $stmt8 = $conn->prepare("SELECT COUNT(*) FROM rental_agreements WHERE landlord_id = ? AND status = 'active'");
    $stmt8->bind_param("i", $landlordId);
    $stmt8->execute();
    $res8 = $stmt8->get_result();
    $row8 = $res8 ? $res8->fetch_row() : [0];
    $activeAgreements = (int)($row8[0] ?? 0);

    // 9. Total Payments Count
    $stmt9 = $conn->prepare("SELECT COUNT(*) FROM payments WHERE landlord_id = ?");
    $stmt9->bind_param("i", $landlordId);
    $stmt9->execute();
    $res9 = $stmt9->get_result();
    $row9 = $res9 ? $res9->fetch_row() : [0];
    $totalPayments = (int)($row9[0] ?? 0);

    // 10. Total Paid Revenue
    $stmt10 = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE landlord_id = ? AND status = 'paid'");
    $stmt10->bind_param("i", $landlordId);
    $stmt10->execute();
    $res10 = $stmt10->get_result();
    $row10 = $res10 ? $res10->fetch_row() : [0];
    $totalRevenue = (float)($row10[0] ?? 0);

    // 11. Maintenance Requests Total
    $stmt11 = $conn->prepare("SELECT COUNT(*) FROM maintenance_requests WHERE landlord_id = ?");
    $stmt11->bind_param("i", $landlordId);
    $stmt11->execute();
    $res11 = $stmt11->get_result();
    $row11 = $res11 ? $res11->fetch_row() : [0];
    $totalMaintenance = (int)($row11[0] ?? 0);

    // 12. Resolved Maintenance Requests
    $stmt12 = $conn->prepare("SELECT COUNT(*) FROM maintenance_requests WHERE landlord_id = ? AND status = 'Resolved'");
    $stmt12->bind_param("i", $landlordId);
    $stmt12->execute();
    $res12 = $stmt12->get_result();
    $row12 = $res12 ? $res12->fetch_row() : [0];
    $resolvedMaintenance = (int)($row12[0] ?? 0);

    // 13. Average Rating
    $stmt13 = $conn->prepare("SELECT COALESCE(AVG(rating), 0) FROM reviews r JOIN properties p ON r.property_id = p.property_id WHERE p.landlord_id = ?");
    $stmt13->bind_param("i", $landlordId);
    $stmt13->execute();
    $res13 = $stmt13->get_result();
    $row13 = $res13 ? $res13->fetch_row() : [0];
    $avgRating = round((float)($row13[0] ?? 0), 2);

    echo json_encode([
        'success' => true,
        'message' => 'Landlord report statistics retrieved successfully.',
        'data'    => [
            'properties' => [
                'total'     => $totalProperties,
                'rented'    => $rentedProperties,
                'available' => $availableProperties
            ],
            'applications' => [
                'total'    => $totalApplications,
                'pending'  => $pendingApplications,
                'approved' => $approvedApplications,
                'rejected' => $rejectedApplications
            ],
            'agreements' => [
                'active' => $activeAgreements
            ],
            'payments' => [
                'count'         => $totalPayments,
                'total_revenue' => $totalRevenue
            ],
            'maintenance' => [
                'total'    => $totalMaintenance,
                'resolved' => $resolvedMaintenance
            ],
            'reviews' => [
                'average_rating' => $avgRating
            ]
        ]
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error generating landlord reports.'
    ]);
    exit;
}

