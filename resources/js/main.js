import ithenticateSimilarityScoreCell from "./Components/ithenticateSimilarityScoreCell.vue";
import IthenticateWorkflowErrorNotice from "./Components/IthenticateWorkflowErrorNotice.vue";

pkp.registry.registerComponent("ithenticateSimilarityScoreCell", ithenticateSimilarityScoreCell);
pkp.registry.registerComponent("IthenticateWorkflowErrorNotice", IthenticateWorkflowErrorNotice);

const { useLocalize } = pkp.modules.useLocalize;
const { useApp } = pkp.modules.useApp;
const { useNotify } = pkp.modules.useNotify;
const { useCurrentUser } = pkp.modules.useCurrentUser;
import { ref, computed, watch, onUnmounted } from "vue";
import { deduceFileStatus, hasSimilarityScore } from "./fileStatus";

/**
 * Per-submission plagiarism sessions, shared by every file manager on the page.
 *
 * More than one file manager can be on screen at once: a review round renders "Revisions
 * Uploaded" (fileManager_WORKFLOW_REVIEW_REVISIONS) directly above "Files for Review"
 * (fileManager_EDITOR_REVIEW_FILES), both for the same submission.
 */
const plagiarismSessions = new Map();

function createPlagiarismSession(submissionId, submissionStageId) {

    const { useUrl } = pkp.modules.useUrl;
    const { useFetch } = pkp.modules.useFetch;
    const { t } = useLocalize();

    const { isOPS } = useApp();
    const { notify } = useNotify();

    let refCount = 0;
    let primed = false;

    const eventSource = ref(null);
    const timeoutId = ref(null);
    const fallbackTimeoutId = ref(null);
    const pollingIntervalId = ref(null);

    const maxDuration = ref(600); // Store maxDuration from server, Default to 600 seconds

    // Each participating file manager contributes a getter for the ids it renders. The server
    // ignores fileIds entirely, but the union is what makes the request reactive to files
    // appearing in — or disappearing from — any of the managers sharing this session.
    const fileIdSources = ref([]);

    const ithenticateRequestParams = computed(() => {
        const fileIds = [
            ...new Set(fileIdSources.value.flatMap((getFileIds) => getFileIds() || [])),
        ];

        return {
            fileIds: fileIds,
            submissionId: submissionId,
            stageId: isOPS() ? pkp.const.WORKFLOW_STAGE_ID_PRODUCTION : submissionStageId
        };
    });

    const { apiUrl } = useUrl(`submissions/${submissionId}/plagiarism/status`);

    const {
        fetch: fetchIthenticateStatus,
        data: ithenticateStatus,
    } = useFetch(
        apiUrl, {
            method: 'POST',
            body: ithenticateRequestParams
        }
    );

    function shouldStreamPlagiarismResults(status) {
        if (!status?.files) {
            return false;
        }
        return Object.values(status.files).some(file =>
            file.ithenticateId !== null &&
            !hasSimilarityScore(file) &&
            // A file with a processing error is terminal — no result will arrive, so stop streaming.
            !file.ithenticateProcessingError
        );
    }

    // Only if require SSE streaming and no SSE stream already active, initiate new SSE stream.
    function ensureStream() {
        if (ithenticateStatus.value
            && shouldStreamPlagiarismResults(ithenticateStatus.value)
            && !eventSource.value) {
            streamPlagiarismResults(ithenticateRequestParams.value);
        }
    }

    watch(
        ithenticateRequestParams,
        async (newRequestParams) => {
            if (newRequestParams?.fileIds?.length) {
                try {
                    await fetchIthenticateStatus();

                    // Only if valid ithenticateStatus data available,
                    // then should look for possibility to initiate SSE stream
                    ensureStream();
                } catch (error) {
                    console.error("Error fetching ithenticateStatus:", error);
                }
            }
        },
        { deep: true }
    );

    // Initial fetch for OPS. Idempotent: the first manager to register primes the session,
    // any later one reuses the status it already fetched.
    async function primeInitialFetch() {
        if (primed) {
            return;
        }
        primed = true;

        try {
            await fetchIthenticateStatus();

            // Only if valid ithenticateStatus data available,
            // then should look for possibility to initiate SSE stream
            if (ithenticateRequestParams.value?.fileIds?.length) {
                ensureStream();
            }
        } catch (error) {
            console.error("Error in initial OPS fetch:", error);
        }
    }

    function closeEventSource() {
        if (eventSource.value) {
            eventSource.value.close();
            eventSource.value = null;
        }
        if (timeoutId.value) {
            clearTimeout(timeoutId.value);
            timeoutId.value = null;
        }
    }

    function clearFallbacks() {
        if (fallbackTimeoutId.value) {
            clearTimeout(fallbackTimeoutId.value);
            fallbackTimeoutId.value = null;
        }
        if (pollingIntervalId.value) {
            clearInterval(pollingIntervalId.value);
            pollingIntervalId.value = null;
        }
    }

    function stop() {
        closeEventSource();
        clearFallbacks();
    }

    // Implementation for streaming plagiarism results
    function streamPlagiarismResults(params) {
        closeEventSource();

        if (!params?.fileIds?.length) {
            return;
        }

        const queryParams = new URLSearchParams({
            submissionId: params.submissionId,
            stageId: params.stageId,
            fileIds: params.fileIds.join(","),
        });
        const streamUrl = `${apiUrl.value}/stream?${queryParams.toString()}`;

        let lastMessageTime = Date.now();
        let retryCount = 0;
        const maxRetries = 3;

        // Initialize EventSource
        try {
            eventSource.value = new EventSource(streamUrl);

            // Fallback to polling if no SSE messages after 30 seconds
            fallbackTimeoutId.value = setTimeout(() => {
                if (Date.now() - lastMessageTime > 30000) {
                    console.log("No SSE messages received, falling back to polling");
                    closeEventSource();
                    pollingIntervalId.value = setInterval(async () => {

                        try {
                            if (!ithenticateStatus.value && retryCount < maxRetries) {
                                await fetchIthenticateStatus();
                                retryCount++;
                            } else if (shouldStreamPlagiarismResults(ithenticateStatus.value)) {
                                await fetchIthenticateStatus();
                                retryCount = 0; // Reset retries on successful fetch
                            } else {
                                clearInterval(pollingIntervalId.value);
                                pollingIntervalId.value = null;
                                retryCount = 0;
                            }
                        } catch (error) {
                            retryCount++;
                            if (retryCount >= maxRetries) {
                                clearInterval(pollingIntervalId.value);
                                pollingIntervalId.value = null;
                                retryCount = 0;
                            }
                        }
                    }, 10000);
                }
            }, 30000);

            // Handle SSE messages
            eventSource.value.onmessage = (event) => {
                lastMessageTime = Date.now();

                try {
                    const data = JSON.parse(event.data);
                    // console.log("Parsed data:", data);

                    // Check for maxDuration from server
                    if (data.maxDuration) {
                        maxDuration.value = Number(data.maxDuration);

                        // Update timeout with new maxDuration
                        if (timeoutId.value) {
                            clearTimeout(timeoutId.value);
                            timeoutId.value = null;
                        }

                        timeoutId.value = setTimeout(() => {
                            console.log(`Client-side ${maxDuration.value}-second limit reached at:`, new Date().toISOString());
                            closeEventSource();
                            clearFallbacks();
                            fetchIthenticateStatus();
                        }, maxDuration.value * 1000);

                        return;
                    }

                    if (data) {

                        const oldFiles = { ...ithenticateStatus.value?.files || {} };

                        ithenticateStatus.value = {
                            ...ithenticateStatus.value,
                            ...data,
                        };

                        let hasNewSimilarityResult = false,
                            hasNewReportScheduled = false;


                        if (data.files && oldFiles) {
                            Object.entries(data.files).forEach(([fileId, newFile]) => {
                                const oldFile = oldFiles[fileId];
                                if (oldFile && oldFile.ithenticateId !== null) {
                                    if (!hasSimilarityScore(oldFile) && hasSimilarityScore(newFile)) {
                                        hasNewSimilarityResult = true;
                                    }

                                    if (oldFile.ithenticateSimilarityScheduled != newFile.ithenticateSimilarityScheduled) {
                                        hasNewReportScheduled = true;
                                    }
                                }
                            });
                        }

                        if (hasNewReportScheduled) {
                            notify(
                                t('plugins.generic.plagiarism.action.scheduleSimilarityReport.success'),
                                'success'
                            );
                        }

                        if (hasNewSimilarityResult) {
                            notify(
                                t('plugins.generic.plagiarism.action.refreshSimilarityResult.success'),
                                'success'
                            );
                        }

                        if (hasNewSimilarityResult || hasNewReportScheduled) {
                            fetchIthenticateStatus();
                        }

                        if (!shouldStreamPlagiarismResults(ithenticateStatus.value)) {
                            // No files require further SSE streaming, close connection
                            closeEventSource();
                            clearFallbacks();

                            fetchIthenticateStatus();
                            return;
                        }
                    }
                } catch (e) {
                    console.error("Error parsing EventSource data:", e);
                }
            };

            // Handle custom stream_end event
            eventSource.value.addEventListener("stream_end", () => {
                closeEventSource();
                clearFallbacks();

                fetchIthenticateStatus();
            });

            eventSource.value.onerror = (event) => {
                closeEventSource();

                if (fallbackTimeoutId.value) {
                    clearTimeout(fallbackTimeoutId.value);
                    fallbackTimeoutId.value = null;
                }

                pollingIntervalId.value = setInterval(() => {
                    if (shouldStreamPlagiarismResults(ithenticateStatus.value)) {
                        fetchIthenticateStatus();
                    } else {
                        // No files require polling, stopping
                        clearInterval(pollingIntervalId.value);
                        pollingIntervalId.value = null;
                    }
                }, 10000);

                fetchIthenticateStatus();
            };

            eventSource.value.onopen = () => {
                console.log("EventSource connection opened at:", new Date().toISOString());
            };

        } catch (e) {
            console.error("Failed to initialize EventSource:", e);

            pollingIntervalId.value = setInterval(() => {
                if (shouldStreamPlagiarismResults(ithenticateStatus.value)) {
                    fetchIthenticateStatus();
                } else {
                    clearInterval(pollingIntervalId.value);
                    pollingIntervalId.value = null;
                }
            }, 10000);
        }

    }

    return {
        status: ithenticateStatus,
        params: ithenticateRequestParams,
        fetchStatus: fetchIthenticateStatus,
        ensureStream,
        primeInitialFetch,
        stop,
        addFileIdSource(getFileIds) {
            fileIdSources.value = [...fileIdSources.value, getFileIds];
        },
        retain() {
            refCount++;
        },
        release() {
            refCount--;

            // The last file manager sharing this submission has gone away — drop the stream
            // rather than leaving it holding a request open for the rest of maxDuration.
            if (refCount <= 0) {
                stop();
                plagiarismSessions.delete(submissionId);
            }
        },
    };
}

function acquirePlagiarismSession(submissionId, submissionStageId) {
    let session = plagiarismSessions.get(submissionId);

    if (!session) {
        session = createPlagiarismSession(submissionId, submissionStageId);
        plagiarismSessions.set(submissionId, session);
    }

    session.retain();

    return session;
}

// One listener for the page rather than one per store: file managers are created and
// disposed as the editor moves between stages, and each would otherwise leave one behind.
window.addEventListener("beforeunload", () => {
    plagiarismSessions.forEach((session) => session.stop());
});

function runPlagiarismAction(piniaContext, stageNamespace) {

    const dashboardStore = pkp.registry.getPiniaStore("dashboard");
    if (dashboardStore.dashboardPage !== "editorialDashboard") {
        return;
    }

    const { t } = useLocalize();

    const { isOPS } = useApp();

    const fileStore = piniaContext.store;
    const { submission, submissionStageId } = fileStore.props;

    const { notify } = useNotify();

    const session = acquirePlagiarismSession(submission.id, submissionStageId);
    const ithenticateStatus = session.status;

    // Contribute this manager's rows to the shared request.
    session.addFileIdSource(() => isOPS()
        ? (fileStore?.galleys?.map((galley) => galley.file.id) || [])
        : (fileStore?.files?.map((file) => file.id) || [])
    );

    // Expose the shared status on this store so ithenticateSimilarityScoreCell, which resolves
    // its store by fileStageNamespace, reads the same object every other manager is reading.
    fileStore.ithenticateStatus = ithenticateStatus;

    if (isOPS()) {
        session.primeInitialFetch();
    }

    onUnmounted(() => {
        session.release();
    });

    fileStore.extender.extendFn('getColumns', (columns, args) => {
        const newColumns = [...columns];

        newColumns.splice(newColumns.length - 1, 0, {
            header: t('plugins.generic.plagiarism.similarity.match.title'),
            component: 'ithenticateSimilarityScoreCell',
            props: {
                fileStageNamespace: stageNamespace
            },
        });

        return newColumns;
    });

    function getLabel(userStatus, submissionStatus, fileStatus)
    {
        // A fresh file, or one with a processing error (terminal), offers a re-run of the check.
        if (!fileStatus.ithenticateId || fileStatus.ithenticateProcessingError) {
            return t('plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.title');
        }

        if (fileStatus.ithenticateId && !fileStatus.ithenticateSimilarityScheduled) {
            return t('plugins.generic.plagiarism.similarity.action.generateReport.title');
        }

        return t('plugins.generic.plagiarism.similarity.action.refreshReport.title');
    }

    function getConfirmationMessage(fileStatus)
    {
        if (!fileStatus.ithenticateId || fileStatus.ithenticateProcessingError) {
            return t('plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.confirmation');
        }

        if (!fileStatus.ithenticateSimilarityScheduled) {
            return t('plugins.generic.plagiarism.similarity.action.generateReport.confirmation');
        }

        return t('plugins.generic.plagiarism.similarity.action.refreshReport.confirmation');
    }

    function getActionUrl(fileStatus)
    {
        // Errored files re-run through the upload action (createNewSubmission), which clears the error.
        if (!fileStatus.ithenticateId || fileStatus.ithenticateProcessingError) {
            return fileStatus.ithenticateUploadUrl;
        }

        if (!fileStatus.ithenticateSimilarityScheduled) {
            return fileStatus.ithenticateReportScheduleUrl;
        }

        return fileStatus.ithenticateReportRefreshUrl;
    }

    function isEulaConfirmationRequired(contextStatus, submissionStatus, userStatus)
    {
        // Check if EULA confirmation required for this tenant
        if (!contextStatus.eulaRequired) {
            return false;
        }

        // If no EULA is stamped with submission
        // means submission never passed through iThenticate process
        if (!submissionStatus.ithenticateEulaVersion) {
            return true;
        }

        // If no EULA is stamped with submitting user
        // means user has never previously interacted with iThenticate process
        if (!userStatus.ithenticateEulaVersion) {
            return true;
        }

        // EULA confirmation is required if
        //  - user did confirm EULA previously but does not match with the latest version anymore
        //  - submission was stampted to EULA previously but does not match with the latest version
        //  - the stamped EULA version of user and submission does not match
        if (userStatus.ithenticateEulaVersion !== contextStatus.eulaVersion
            || submissionStatus.ithenticateEulaVersion !== contextStatus.eulaVersion
            || submissionStatus.ithenticateEulaVersion !== userStatus.ithenticateEulaVersion) {
            return true;
        }

        return false;
    }

    async function executePlagiarismAction(fileStatus)
    {
        const actionUrl = getActionUrl(fileStatus);

        const { useFetch } = pkp.modules.useFetch;

        const {
            fetch: executeIthenticateAction,
            data: ithenticateActionData,
        } = useFetch(actionUrl);

        await executeIthenticateAction();

        return ithenticateActionData;
    }

    fileStore.extender.extendFn('getItemActions', (originalResult, args) => {
        const submission = fileStore.props.submission;
        const submissionFile = isOPS() ? args.galley.file : args.file;

        // For OJS and OMP, actions are one allowed proper current workflow stage
        // e.g. when submission stage match the current workflow stage
        // and for OPS, as only available stage is production after submission done
        if (!isOPS() && (submission.stageId !== submissionStageId)) {
            return [...originalResult];
        }

        if (ithenticateStatus.value) {
            const fileStatus = deduceFileStatus(submissionFile, ithenticateStatus.value);
            const userStatus = ithenticateStatus.value?.user;
            const submissionStatus = ithenticateStatus.value?.submission;
            const contextStatus = ithenticateStatus.value?.context;

            // If file status is not found, return original result
            if (!fileStatus) {
                return [...originalResult];
            }

            // Action on non allowed file is restricted
            if (!fileStatus.ithenticateUploadAllowed) {
                return [...originalResult];
            }

            const { hasCurrentUserAtLeastOneRole } = useCurrentUser();
            if (!hasCurrentUserAtLeastOneRole(userStatus.ithenticateActionAllowedRoles)) {
                return [...originalResult];
            }

            return [
                ...originalResult,
                {
                    label: getLabel(userStatus, submissionStatus, fileStatus),
                    name: "conductPlagiarismCheck",
                    icon: "Globe",
                    actionFn: (args) => {

                        if ((!fileStatus.ithenticateId || fileStatus.ithenticateProcessingError) && isEulaConfirmationRequired(contextStatus, submissionStatus, userStatus)) {
                            const {useLegacyGridUrl} = pkp.modules.useLegacyGridUrl;

                            const {openLegacyModal} = useLegacyGridUrl({
                                component: 'plugins.generic.plagiarism.controllers.PlagiarismIthenticateHandler',
                                op: 'confirmEula',
                                params: {
                                    submissionId: submissionFile.submissionId,
                                    submissionFileId: submissionFile.id,
                                    stageId: submission.stageId,
                                },
                            });

                            openLegacyModal(
                                {
                                    title: t('plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.title')
                                },
                                async () => {
                                    await session.fetchStatus();

                                    session.ensureStream();
                                },
                            );

                            return;
                        }

                        const { useModal } = pkp.modules.useModal;
                        const { openDialog } = useModal();

                        openDialog({
                            title: getLabel(userStatus, submissionStatus, fileStatus),
                            message: getConfirmationMessage(fileStatus),
                            actions: [
                                {
                                    label: t('common.yes'),
                                    isPrimary: true,
                                    callback: async (close) => {
                                        close();

                                        const ithenticateActionData = await executePlagiarismAction(fileStatus);

                                        // If the server detected a stale-cache EULA mismatch it busted the
                                        // cache, reverted the stale stamps, and returned the "EULA updated"
                                        // notification below. The fetchStatus() call that follows
                                        // refreshes contextStatus.eulaVersion (and the now-null user/submission
                                        // stamps), so isEulaConfirmationRequired() naturally surfaces the EULA
                                        // modal on the user's next click — no explicit reconfirmation signal.
                                        if (ithenticateActionData.value?.content) {
                                            notify(
                                                ithenticateActionData.value.content,
                                                ithenticateActionData.value?.status ? 'success': 'warning'
                                            );
                                        }

                                        await session.fetchStatus();

                                        session.ensureStream();
                                    },
                                },
                                {
                                    label: t('common.no'),
                                    isWarnable: true,
                                    callback: (close) => {
                                        close();
                                    },
                                },
                            ],
                        });
                    },
                },
            ];
        }

        return [...originalResult];
    });
}

pkp.registry.storeExtend('fileManager_SUBMISSION_FILES', (piniaContext) => {
    runPlagiarismAction(piniaContext, 'fileManager_SUBMISSION_FILES');
});

pkp.registry.storeExtend('fileManager_EDITOR_REVIEW_FILES', (piniaContext) => {
    runPlagiarismAction(piniaContext, 'fileManager_EDITOR_REVIEW_FILES');
});


pkp.registry.storeExtend('fileManager_WORKFLOW_REVIEW_REVISIONS', (piniaContext) => {
    runPlagiarismAction(piniaContext, 'fileManager_WORKFLOW_REVIEW_REVISIONS');
});

pkp.registry.storeExtend('galleyManager', (piniaContext) => {
    const { isOPS } = useApp();

    if (!isOPS()) {
        return;
    }

    runPlagiarismAction(piniaContext, 'galleyManager');
});

pkp.registry.storeExtend('workflow', (piniaContext) => {
    const workflowStore = piniaContext.store;
    const { isOPS } = useApp();

    // Submission-level plagiarism errors render as a full-width block in the workflow primary
    // content, right after the Submission Files panel (OJS/OMP) or the galley manager (OPS). Scope
    // the match by app: on OJS the standalone `GalleyManager` is the Publication > Galleys section,
    // where the submission-files store does not exist — matching it there would insert the notice
    // against a missing store. Placed AFTER the manager so its Pinia store exists when read.
    workflowStore.extender.extendFn('getPrimaryItems', (items) => {
        // OPS's workflow config resolver can return a non-array (undefined) for transient/empty menu
        // states (unlike OJS, which falls back to []). Preserve the base result untouched rather than
        // calling findIndex on a non-array
        if (!Array.isArray(items)) {
            return items;
        }

        const index = items.findIndex((item) =>
            isOPS()
                ? item.component === 'GalleyManager'
                : (item.component === 'FileManager' && item.props?.namespace === 'SUBMISSION_FILES'),
        );

        if (index === -1) {
            return items;
        }

        const next = [...items];
        next.splice(index + 1, 0, {
            component: 'IthenticateWorkflowErrorNotice',
            props: { fileStageNamespace: 'fileManager_SUBMISSION_FILES' },
        });

        return next;
    });
});
