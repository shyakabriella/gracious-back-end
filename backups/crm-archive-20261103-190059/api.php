<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StaffInvitationController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\PropertyWorkflowController;
use App\Http\Controllers\Api\PublicPropertyController;
use App\Http\Controllers\Api\PropertyImageController;
use App\Http\Controllers\Api\PropertyDocumentController;
use App\Http\Controllers\Api\PropertyOwnerController;
use App\Http\Controllers\Api\PropertyOwnershipController;
use App\Http\Controllers\Api\PropertyFeaturedController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\LeadWorkflowController;
use App\Http\Controllers\Api\LeadActivityController;
use App\Http\Controllers\Api\LeadFollowUpController;
use App\Http\Controllers\Api\PublicPropertyInquiryController;
use App\Http\Controllers\Api\UnassignedLeadController;
use App\Http\Controllers\Api\PropertyViewingController;
use App\Http\Controllers\Api\PropertyViewingStatusController;
use App\Http\Controllers\Api\PropertySaleController;
use App\Http\Controllers\Api\PropertySaleStatusController;
use App\Http\Controllers\Api\PropertySalePaymentController;
use App\Http\Controllers\Api\PropertySalePaymentEvidenceController;
use App\Http\Controllers\Api\PropertySaleCompletionController;
use App\Http\Controllers\Api\PropertySaleCompletionDocumentController;
use App\Http\Controllers\Api\PropertyRentalController;
use App\Http\Controllers\Api\PropertyRentalStatusController;
use App\Http\Controllers\Api\PropertyRentPaymentController;
use App\Http\Controllers\Api\PropertyRentPaymentEvidenceController;
use App\Http\Controllers\Api\PropertyRentPaymentVerificationController;
use App\Http\Controllers\Api\PropertyRentStatementController;
use App\Http\Controllers\Api\PropertyRentRefundController;
use App\Http\Controllers\Api\PropertyRentRefundApprovalController;
use App\Http\Controllers\Api\PropertyRentRefundEvidenceController;
use App\Http\Controllers\Api\PropertyRentRefundProcessingController;
use App\Http\Controllers\Api\PropertyRentRefundDestinationController;
use App\Http\Controllers\Api\PropertyRentRefundReconciliationController;
use Illuminate\Support\Facades\Route;


Route::post('/public/properties/{slug}/inquiries', [
    PublicPropertyInquiryController::class, 'store'
])->middleware('throttle:5,1');

Route::get('/public/filters', [
    PublicPropertyController::class, 'filters'
]);

Route::get('/public/home', [
    PublicPropertyController::class, 'home'
]);

Route::get('/public/properties', [
    PublicPropertyController::class, 'index'
]);

Route::get('/public/properties/{slug}', [
    PublicPropertyController::class, 'show'
]);

Route::get('/public/properties/{property}/images/{image}', [
    PropertyImageController::class, 'publicShow'
]);

Route::post('/staff/invitations/accept', [
    StaffInvitationController::class, 'accept'
])->middleware('throttle:5,1');

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');

Route::middleware([
    'auth:sanctum',
    EnsureUserIsActive::class,
])->group(function () {
    Route::post('/staff/invitations', [
        StaffInvitationController::class, 'store'
    ]);

    Route::post('/staff/{user}/invitation/resend', [
        StaffInvitationController::class, 'resend'
    ]);

    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/change-password', [
        AuthController::class,
        'changePassword',
    ]);

    Route::get('/dashboard/summary', [
        DashboardController::class,
        'summary',
    ]);

    Route::get('/property-categories', [
        PropertyController::class, 'categories'
    ]);

    Route::get('/properties', [PropertyController::class, 'index']);
    Route::post('/properties', [PropertyController::class, 'store']);
    Route::get('/properties/{property}', [PropertyController::class, 'show']);
    Route::put('/properties/{property}', [PropertyController::class, 'update']);
    Route::delete('/properties/{property}', [PropertyController::class, 'destroy']);


    Route::post('/properties/{property}/submit', [
        PropertyWorkflowController::class, 'submit'
    ]);

    Route::patch('/properties/{property}/verification', [
        PropertyWorkflowController::class, 'verify'
    ]);

    Route::get('/properties/{property}/verification-history', [
        PropertyWorkflowController::class, 'history'
    ]);

    Route::patch('/properties/{property}/featured', [
        PropertyFeaturedController::class, 'update'
    ]);

    Route::patch('/properties/{property}/publish', [
        PropertyWorkflowController::class, 'publish'
    ]);

    Route::patch('/properties/{property}/availability', [
        PropertyWorkflowController::class, 'availability'
    ]);

    Route::patch('/properties/{property}/assign-agent', [
        PropertyWorkflowController::class, 'assignAgent'
    ]);

    Route::patch('/properties/{property}/images/reorder', [
        PropertyImageController::class, 'reorder'
    ]);

    Route::get('/properties/{property}/images', [
        PropertyImageController::class, 'index'
    ]);

    Route::post('/properties/{property}/images', [
        PropertyImageController::class, 'store'
    ]);

    Route::get('/properties/{property}/images/{image}', [
        PropertyImageController::class, 'show'
    ]);

    Route::patch('/properties/{property}/images/{image}/cover', [
        PropertyImageController::class, 'cover'
    ]);

    Route::delete('/properties/{property}/images/{image}', [
        PropertyImageController::class, 'destroy'
    ]);

    Route::get('/properties/{property}/documents', [
        PropertyDocumentController::class, 'index'
    ]);

    Route::post('/properties/{property}/documents', [
        PropertyDocumentController::class, 'store'
    ]);

    Route::get('/properties/{property}/documents/{document}/download', [
        PropertyDocumentController::class, 'download'
    ]);

    Route::delete('/properties/{property}/documents/{document}', [
        PropertyDocumentController::class, 'destroy'
    ]);

    Route::get('/properties/{property}/owners', [
        PropertyOwnershipController::class, 'index'
    ]);

    Route::post('/properties/{property}/owners', [
        PropertyOwnershipController::class, 'attach'
    ]);

    Route::delete('/properties/{property}/owners/{owner}', [
        PropertyOwnershipController::class, 'detach'
    ]);

    Route::get('/leads', [
        LeadController::class, 'index'
    ]);

    Route::post('/leads', [
        LeadController::class, 'store'
    ]);

    Route::get('/leads/{lead}', [
        LeadController::class, 'show'
    ]);

    Route::patch('/leads/{lead}/assign', [
        LeadWorkflowController::class, 'assign'
    ]);

    Route::patch('/leads/{lead}/status', [
        LeadWorkflowController::class, 'status'
    ]);

    Route::get('/leads/{lead}/activities', [
        LeadWorkflowController::class, 'activities'
    ]);

    Route::post('/leads/{lead}/activities', [
        LeadActivityController::class, 'store'
    ]);



    Route::get('/viewings', [
        PropertyViewingController::class, 'index'
    ]);

    Route::post('/viewings', [
        PropertyViewingController::class, 'store'
    ]);

    Route::get('/viewings/{viewing}', [
        PropertyViewingController::class, 'show'
    ]);

    Route::patch('/viewings/{viewing}/confirm', [
        PropertyViewingStatusController::class, 'confirm'
    ]);

    Route::patch('/viewings/{viewing}/cancel', [
        PropertyViewingStatusController::class, 'cancel'
    ]);

    Route::patch('/viewings/{viewing}/complete', [
        PropertyViewingStatusController::class, 'complete'
    ]);

    Route::patch('/viewings/{viewing}/no-show', [
        PropertyViewingStatusController::class, 'noShow'
    ]);

    Route::get('/viewings/{viewing}/history', [
        PropertyViewingStatusController::class, 'history'
    ]);

    Route::get('/rentals', [
        PropertyRentalController::class, 'index'
    ]);

    Route::post('/rentals', [
        PropertyRentalController::class, 'store'
    ]);

    Route::get('/rentals/{rental}', [
        PropertyRentalController::class, 'show'
    ]);

    Route::patch('/rentals/{rental}/activate', [
        PropertyRentalStatusController::class, 'activate'
    ]);

    Route::patch('/rentals/{rental}/cancel', [
        PropertyRentalStatusController::class, 'cancel'
    ]);

    Route::patch('/rentals/{rental}/terminate', [
        PropertyRentalStatusController::class, 'terminate'
    ]);

    Route::patch('/rentals/{rental}/expire', [
        PropertyRentalStatusController::class, 'expire'
    ]);

    Route::get('/rentals/{rental}/history', [
        PropertyRentalStatusController::class, 'history'
    ]);

    Route::get('/rentals/{rental}/statement', [
        PropertyRentStatementController::class, 'show'
    ]);

    Route::get('/rentals/{rental}/refunds', [
        PropertyRentRefundController::class, 'index'
    ]);

    Route::post('/rentals/{rental}/refunds', [
        PropertyRentRefundController::class, 'store'
    ]);

    Route::patch('/rentals/{rental}/refunds/{refund}/approve', [
        PropertyRentRefundApprovalController::class, 'approve'
    ]);

    Route::patch('/rentals/{rental}/refunds/{refund}/reject', [
        PropertyRentRefundApprovalController::class, 'reject'
    ]);

    Route::patch('/rentals/{rental}/refunds/{refund}/process', [
        PropertyRentRefundProcessingController::class, 'start'
    ]);

    Route::post('/rentals/{rental}/refunds/{refund}/destination', [
        PropertyRentRefundDestinationController::class, 'store'
    ]);

    Route::patch('/rentals/{rental}/refunds/{refund}/destination/approve', [
        PropertyRentRefundDestinationController::class, 'approve'
    ]);

    Route::post('/rentals/{rental}/refunds/{refund}/reconcile', [
        PropertyRentRefundReconciliationController::class, 'reconcile'
    ]);

    Route::get('/rentals/{rental}/refunds/{refund}/evidences', [
        PropertyRentRefundEvidenceController::class, 'index'
    ]);

    Route::post('/rentals/{rental}/refunds/{refund}/evidences', [
        PropertyRentRefundEvidenceController::class, 'store'
    ]);

    Route::get('/rentals/{rental}/refunds/{refund}/evidences/{evidence}/download', [
        PropertyRentRefundEvidenceController::class, 'download'
    ]);

    Route::get('/rentals/{rental}/payments', [
        PropertyRentPaymentController::class, 'index'
    ]);

    Route::post('/rentals/{rental}/payments', [
        PropertyRentPaymentController::class, 'store'
    ]);

    Route::get('/rentals/{rental}/payments/{payment}/evidences', [
        PropertyRentPaymentEvidenceController::class, 'index'
    ]);

    Route::post('/rentals/{rental}/payments/{payment}/evidences', [
        PropertyRentPaymentEvidenceController::class, 'store'
    ]);

    Route::patch('/rentals/{rental}/payments/{payment}/verify', [
        PropertyRentPaymentVerificationController::class, 'verify'
    ]);

    Route::patch('/rentals/{rental}/payments/{payment}/reject', [
        PropertyRentPaymentVerificationController::class, 'reject'
    ]);

    Route::get('/rentals/{rental}/payments/{payment}/history', [
        PropertyRentPaymentVerificationController::class, 'history'
    ]);

    Route::get('/rentals/{rental}/payments/{payment}/evidences/{evidence}/download', [
        PropertyRentPaymentEvidenceController::class, 'download'
    ]);

    Route::patch('/rentals/{rental}', [
        PropertyRentalController::class, 'update'
    ]);

    Route::get('/sales', [
        PropertySaleController::class, 'index'
    ]);

    Route::post('/sales', [
        PropertySaleController::class, 'store'
    ]);

    Route::get('/sales/{sale}', [
        PropertySaleController::class, 'show'
    ]);

    Route::patch('/sales/{sale}/agreement', [
        PropertySaleStatusController::class, 'agreement'
    ]);

    Route::patch('/sales/{sale}/pending-completion', [
        PropertySaleStatusController::class, 'pendingCompletion'
    ]);

    Route::patch('/sales/{sale}/cancel', [
        PropertySaleStatusController::class, 'cancel'
    ]);

    Route::get('/sales/{sale}/history', [
        PropertySaleStatusController::class, 'history'
    ]);

    Route::get('/sales/{sale}/payments', [
        PropertySalePaymentController::class, 'index'
    ]);

    Route::post('/sales/{sale}/payments', [
        PropertySalePaymentController::class, 'store'
    ]);

    Route::patch('/sales/{sale}/payments/{payment}/verify', [
        PropertySalePaymentController::class, 'verify'
    ]);

    Route::patch('/sales/{sale}/payments/{payment}/reject', [
        PropertySalePaymentController::class, 'reject'
    ]);

    Route::get('/sales/{sale}/payments/{payment}/evidences', [
        PropertySalePaymentEvidenceController::class, 'index'
    ]);

    Route::post('/sales/{sale}/payments/{payment}/evidences', [
        PropertySalePaymentEvidenceController::class, 'store'
    ]);

    Route::get('/sales/{sale}/payments/{payment}/evidences/{evidence}/download', [
        PropertySalePaymentEvidenceController::class, 'download'
    ]);

    Route::get('/sales/{sale}/completion-documents', [
        PropertySaleCompletionDocumentController::class, 'index'
    ]);

    Route::post('/sales/{sale}/completion-documents', [
        PropertySaleCompletionDocumentController::class, 'store'
    ]);

    Route::get('/sales/{sale}/completion-documents/{document}/download', [
        PropertySaleCompletionDocumentController::class, 'download'
    ]);

    Route::patch('/sales/{sale}/complete', [
        PropertySaleCompletionController::class, 'complete'
    ]);

    Route::get('/crm/unassigned-leads', [
        UnassignedLeadController::class, 'index'
    ]);

    Route::get('/crm/follow-ups', [
        LeadFollowUpController::class, 'index'
    ]);

    Route::patch('/leads/{lead}/activities/{activity}/complete', [
        LeadFollowUpController::class, 'complete'
    ]);

    Route::get('/customers', [
        CustomerController::class, 'index'
    ]);

    Route::post('/customers', [
        CustomerController::class, 'store'
    ]);

    Route::get('/customers/{customer}', [
        CustomerController::class, 'show'
    ]);

    Route::put('/customers/{customer}', [
        CustomerController::class, 'update'
    ]);

    Route::get('/owners', [
        PropertyOwnerController::class, 'index'
    ]);

    Route::post('/owners', [
        PropertyOwnerController::class, 'store'
    ]);

    Route::get('/owners/{owner}/properties', [
        PropertyOwnerController::class, 'properties'
    ]);

    Route::get('/owners/{owner}', [
        PropertyOwnerController::class, 'show'
    ]);

    Route::put('/owners/{owner}', [
        PropertyOwnerController::class, 'update'
    ]);

    Route::delete('/owners/{owner}', [
        PropertyOwnerController::class, 'destroy'
    ]);

    Route::get('/roles', [UserController::class, 'roles']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::patch('/users/{user}/status', [
        UserController::class,
        'changeStatus',
    ]);
});
