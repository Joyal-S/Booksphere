<?php

declare(strict_types=1);

/**
 * admin/google-books/partials/_summary-modal.php
 *
 * The run report dialog (Phase 10.5 import + 10.6 sync). Rendered once
 * per page; google-books.js fills its texts and stats when the run's
 * `summary` server-sent event arrives and toggles the import vs sync
 * stat groups (the same skeleton serves both reports). Without
 * JavaScript the form posts redirect + flash the report line instead,
 * so this modal is progressive enhancement only.
 *
 * No variables are read here - the skeleton is static and the content
 * is injected entirely by the client script.
 */
?>
<div class="modal fade" id="gbSummaryModal" tabindex="-1" role="dialog" aria-hidden="true" data-gb-summary-modal>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" data-gb-summary-title>Run summary</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <p class="gb-summary-message" data-gb-summary-message></p>

                <div class="gb-summary-stats">
                    <!-- Import group (Phase 10.5) -->
                    <div class="gb-summary-stat gb-summary-stat--imported" data-gb-summary-group="import">
                        <strong data-gb-summary-imported>0</strong>
                        <small>Imported</small>
                    </div>
                    <div class="gb-summary-stat gb-summary-stat--duplicates" data-gb-summary-group="import">
                        <strong data-gb-summary-duplicates>0</strong>
                        <small>Duplicates</small>
                    </div>
                    <!-- Sync group (Phase 10.6) -->
                    <div class="gb-summary-stat gb-summary-stat--updated" data-gb-summary-group="sync" hidden>
                        <strong data-gb-summary-updated>0</strong>
                        <small>Updated</small>
                    </div>
                    <div class="gb-summary-stat gb-summary-stat--unchanged" data-gb-summary-group="sync" hidden>
                        <strong data-gb-summary-unchanged>0</strong>
                        <small>Unchanged</small>
                    </div>
                    <div class="gb-summary-stat gb-summary-stat--failed">
                        <strong data-gb-summary-failed>0</strong>
                        <small>Failed</small>
                    </div>
                    <div class="gb-summary-stat gb-summary-stat--skipped">
                        <strong data-gb-summary-skipped>0</strong>
                        <small>Skipped</small>
                    </div>
                    <div class="gb-summary-stat gb-summary-stat--total">
                        <strong data-gb-summary-total>0</strong>
                        <small>Total</small>
                    </div>
                </div>

                <p class="gb-summary-elapsed" data-gb-summary-elapsed></p>

                <div class="gb-summary-failures" data-gb-summary-failures hidden>
                    <h3 class="h6" data-gb-summary-failures-title>Not imported</h3>
                    <ul class="gb-summary-failure-list" data-gb-summary-failure-list></ul>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Done</button>
            </div>
        </div>
    </div>
</div>