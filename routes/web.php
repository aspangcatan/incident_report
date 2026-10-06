<?php

use App\Http\Controllers\Admin\IncidentTypeController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CorrectiveActionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\LeadershipDepartmentController;
use App\Http\Controllers\LessonsLearnedController;
use App\Http\Controllers\RecurrenceReviewController;
use App\Http\Controllers\SafetyAlertController;
use App\Http\Controllers\GuestReportController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\IncidentWorkflowController;
use App\Http\Controllers\InvestigationController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

// Public incident reporting for patients, relatives and visitors (no login) —
// intentionally outside both the 'guest' (unauthenticated-only) and 'auth' groups.
Route::get('/report', [GuestReportController::class, 'create'])->name('guest-report.create');
Route::post('/report', [GuestReportController::class, 'store'])->middleware('throttle:3,60')->name('guest-report.store');
Route::get('/report/submitted', [GuestReportController::class, 'submitted'])->name('guest-report.submitted');

Route::middleware(['auth', 'tdh.active'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/incidents', [IncidentController::class, 'index'])->name('incidents.index');
    Route::get('/incidents/create', [IncidentController::class, 'create'])->name('incidents.create');
    Route::post('/incidents', [IncidentController::class, 'store'])->name('incidents.store');
    Route::get('/incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
    Route::get('/incidents/{incident}/edit', [IncidentController::class, 'edit'])->name('incidents.edit');
    Route::patch('/incidents/{incident}', [IncidentController::class, 'update'])->name('incidents.update');
    Route::post('/incidents/{incident}/review', [IncidentWorkflowController::class, 'review'])->name('incidents.review');
    Route::post('/incidents/{incident}/return', [IncidentWorkflowController::class, 'returnForRevision'])->name('incidents.return');
    Route::post('/incidents/{incident}/assessment', [IncidentWorkflowController::class, 'saveAssessment'])->name('incidents.assessment.save');
    Route::post('/incidents/{incident}/assessment/complete', [IncidentWorkflowController::class, 'completeAssessment'])->name('incidents.assessment.complete');
    Route::post('/incidents/{incident}/return-to-department', [IncidentWorkflowController::class, 'returnToDepartment'])->name('incidents.return-to-department');
    Route::post('/incidents/{incident}/assign', [IncidentWorkflowController::class, 'assign'])->name('incidents.assign');
    Route::post('/incidents/{incident}/effectiveness', [IncidentWorkflowController::class, 'recordEffectiveness'])->name('incidents.effectiveness');
    Route::post('/incidents/{incident}/evidence-preserved', [IncidentWorkflowController::class, 'confirmEvidencePreserved'])->name('incidents.evidence-preserved');
    Route::post('/incidents/{incident}/skip-investigation', [IncidentWorkflowController::class, 'skipInvestigation'])->name('incidents.skip-investigation');
    Route::post('/incidents/{incident}/investigation', [InvestigationController::class, 'start'])->name('incidents.investigation.start');
    Route::post('/investigations/{investigation}/team-members', [InvestigationController::class, 'addTeamMember'])->name('investigations.team-members.store');
    Route::delete('/investigations/{investigation}/team-members/{teamMember}', [InvestigationController::class, 'removeTeamMember'])->name('investigations.team-members.destroy');
    Route::post('/investigations/{investigation}/findings', [InvestigationController::class, 'addFinding'])->name('investigations.findings.store');
    Route::patch('/investigations/{investigation}/findings/{finding}', [InvestigationController::class, 'updateFinding'])->name('investigations.findings.update');
    Route::delete('/investigations/{investigation}/findings/{finding}', [InvestigationController::class, 'deleteFinding'])->name('investigations.findings.destroy');
    Route::post('/investigations/{investigation}/complete', [InvestigationController::class, 'complete'])->name('investigations.complete');
    Route::get('/corrective-actions', [CorrectiveActionController::class, 'index'])->name('corrective-actions.index');
    Route::post('/incidents/{incident}/corrective-actions', [CorrectiveActionController::class, 'store'])->name('corrective-actions.store');
    Route::patch('/corrective-actions/{correctiveAction}', [CorrectiveActionController::class, 'update'])->name('corrective-actions.update');
    Route::post('/corrective-actions/{correctiveAction}/progress', [CorrectiveActionController::class, 'progress'])->name('corrective-actions.progress');
    Route::post('/corrective-actions/{correctiveAction}/complete', [CorrectiveActionController::class, 'complete'])->name('corrective-actions.complete');
    Route::post('/corrective-actions/{correctiveAction}/verify', [CorrectiveActionController::class, 'verify'])->name('corrective-actions.verify');
    Route::post('/incidents/{incident}/request-approval', [ApprovalController::class, 'requestApproval'])->name('approvals.request');
    Route::post('/incidents/{incident}/no-corrective-action-needed', [ApprovalController::class, 'markNoCorrectiveActionNeeded'])->name('approvals.no-corrective-action');
    Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
    Route::post('/approvals/{approval}/return', [ApprovalController::class, 'returnForRevision'])->name('approvals.return');
    Route::post('/incidents/{incident}/evidence', [EvidenceController::class, 'store'])->name('incidents.evidence.store');
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('/analytics/trends', [AnalyticsController::class, 'trends'])->name('analytics.trends');

    Route::get('/safety-alerts', [SafetyAlertController::class, 'index'])->name('safety-alerts.index');
    Route::get('/safety-alerts/create', [SafetyAlertController::class, 'create'])->name('safety-alerts.create');
    Route::post('/safety-alerts', [SafetyAlertController::class, 'store'])->name('safety-alerts.store');
    Route::get('/safety-alerts/{safetyAlert}', [SafetyAlertController::class, 'show'])->name('safety-alerts.show');
    Route::post('/safety-alerts/{safetyAlert}/acknowledge', [SafetyAlertController::class, 'acknowledge'])->name('safety-alerts.acknowledge');
    Route::get('/recurrence-reviews', [RecurrenceReviewController::class, 'index'])->name('recurrence-reviews.index');
    Route::get('/recurrence-reviews/create', [RecurrenceReviewController::class, 'create'])->name('recurrence-reviews.create');
    Route::post('/recurrence-reviews', [RecurrenceReviewController::class, 'store'])->name('recurrence-reviews.store');
    Route::get('/recurrence-reviews/{recurrenceReview}', [RecurrenceReviewController::class, 'show'])->name('recurrence-reviews.show');
    Route::post('/recurrence-reviews/{recurrenceReview}/submit', [RecurrenceReviewController::class, 'submit'])->name('recurrence-reviews.submit');
    Route::post('/recurrence-reviews/{recurrenceReview}/decide', [RecurrenceReviewController::class, 'decide'])->name('recurrence-reviews.decide');
    Route::get('/lessons-learned', [LessonsLearnedController::class, 'index'])->name('lessons.index');

    Route::get('/admin/leadership', [LeadershipDepartmentController::class, 'index'])->name('admin.leadership.index');
    Route::put('/admin/leadership/{user}', [LeadershipDepartmentController::class, 'update'])->name('admin.leadership.update');
    Route::get('/admin/incident-types', [IncidentTypeController::class, 'index'])->name('admin.incident-types.index');
    Route::post('/admin/incident-types', [IncidentTypeController::class, 'store'])->name('admin.incident-types.store');
});
