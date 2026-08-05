<?php

declare(strict_types=1);

/**
 * reviews/partials/_report-modal.php
 *
 * The shared REPORT modal of the Reviews module (Phase 7.5). One
 * modal serves every review card on a page: each card's Report
 * button carries data-report-id; the handler in reviews.js copies
 * it into the form action before the dialog opens (the same
 * one-modal-per-page pattern as _delete-modal.php).
 *
 * The modal submits via fetch (X-Requested-With: fetch) to
 * POST /reviews/{id}/report:
 *
 *     - validation errors come back 422 with per-field messages,
 *       rendered inline under the reason select / description box
 *     - a duplicate report or any other rule failure comes back
 *       409 with one message in the general alert
 *     - success swaps the form for the "Thank you" state
 *
 * Accessibility: aria-labelledby / aria-describedby announce the
 * dialog; the success state stays inside the dialog so focus is
 * never lost.
 */

$reasons = \BookSphere\App\Requests\ReportReviewRequest::REASONS;
?>
<div class="modal fade" id="reviewReportModal" tabindex="-1" role="dialog"
     aria-labelledby="reviewReportModalLabel" aria-describedby="reviewReportModalDesc" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="reviewReportForm" method="post" action="" novalidate>
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="reviewReportModalLabel">Report this review</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3" id="reviewReportModalDesc">
                        Tell us what is wrong with this review. Our moderators will review
                        your report and take action if it breaks our community guidelines.
                    </p>
                    <div class="mb-3">
                        <label class="form-label" for="reportReason">Reason</label>
                        <select class="form-select" id="reportReason" name="reason" required>
                            <option value="" selected>Choose a reason&hellip;</option>
                            <?php foreach ($reasons as $value => $label): ?>
                                <option value="<?= e($value) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback" id="reportReasonError"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="reportDescription">
                            Description <span class="text-muted fw-normal">(optional)</span>
                        </label>
                        <textarea class="form-control" id="reportDescription" name="description"
                                  rows="3" maxlength="1000"
                                  placeholder="Anything that helps our moderators understand the problem&hellip;"></textarea>
                        <div class="invalid-feedback" id="reportDescriptionError"></div>
                    </div>
                    <div class="alert alert-danger d-none mb-0" id="reportGeneralError" role="alert"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" id="reportSubmitBtn">
                        <i class="fa-regular fa-flag me-1" aria-hidden="true"></i>Submit report
                    </button>
                </div>
            </form>
            <div class="modal-body text-center p-4 d-none" id="reportSuccessState">
                <i class="fa-solid fa-circle-check text-success fa-2x mb-2" aria-hidden="true"></i>
                <h2 class="fs-5 mb-1">Thank you. Your report has been submitted.</h2>
                <p class="text-muted small mb-0">Our moderators will review it and take action if needed.</p>
            </div>
        </div>
    </div>
</div>
