(function(vue) {
  "use strict";
  function deduceFileStatus(submissionFile, ithenticateDataStatus) {
    var _a, _b, _c, _d, _e;
    const fileId = submissionFile.id;
    const sourceSubmissionFileId = submissionFile.sourceSubmissionFileId;
    if ((_b = (_a = ithenticateDataStatus == null ? void 0 : ithenticateDataStatus.files) == null ? void 0 : _a[fileId]) == null ? void 0 : _b.ithenticateId) {
      return (_c = ithenticateDataStatus == null ? void 0 : ithenticateDataStatus.files) == null ? void 0 : _c[fileId];
    }
    const sourceSubmissionFile = (_d = ithenticateDataStatus == null ? void 0 : ithenticateDataStatus.files) == null ? void 0 : _d[sourceSubmissionFileId];
    if (sourceSubmissionFile && sourceSubmissionFile.fileId === submissionFile.fileId) {
      return sourceSubmissionFile;
    }
    return (_e = ithenticateDataStatus == null ? void 0 : ithenticateDataStatus.files) == null ? void 0 : _e[fileId];
  }
  const _export_sfc = (sfc, props) => {
    const target = sfc.__vccOpts || sfc;
    for (const [key, val] of props) {
      target[key] = val;
    }
    return target;
  };
  const _hoisted_1 = {
    key: 0,
    class: "plagiarism-similarity-score"
  };
  const _hoisted_2 = ["href", "title"];
  const _hoisted_3 = ["alt", "src"];
  const _hoisted_4 = {
    key: 1,
    class: "plagiarism-status"
  };
  const _hoisted_5 = { class: "tooltip" };
  const _hoisted_6 = { key: 0 };
  const _hoisted_7 = { key: 1 };
  const _sfc_main = {
    __name: "ithenticateSimilarityScoreCell",
    props: {
      file: { type: Object, required: false },
      galley: { type: Object, required: false },
      fileStageNamespace: { type: String, required: false }
    },
    setup(__props) {
      const { useApp: useApp2 } = pkp.modules.useApp;
      const props = __props;
      const { isOPS } = useApp2();
      const fileStore = isOPS() ? pkp.registry.getPiniaStore("galleyManager") : pkp.registry.getPiniaStore(props.fileStageNamespace);
      const fileStatus = vue.computed(() => {
        const submissionFile = isOPS() ? props.galley.file : props.file;
        if (!(fileStore == null ? void 0 : fileStore.ithenticateStatus)) {
          return {};
        }
        const status = deduceFileStatus(submissionFile, fileStore.ithenticateStatus || {});
        return status;
      });
      return (_ctx, _cache) => {
        const _component_icon = vue.resolveComponent("icon");
        const _component_PkpTableCell = vue.resolveComponent("PkpTableCell");
        return vue.openBlock(), vue.createBlock(_component_PkpTableCell, null, {
          default: vue.withCtx(() => {
            var _a, _b, _c;
            return [
              ((_a = fileStatus.value) == null ? void 0 : _a.ithenticateSimilarityResult) ? (vue.openBlock(), vue.createElementBlock("span", _hoisted_1, [
                vue.createElementVNode("a", {
                  href: fileStatus.value.ithenticateViewerUrl ?? "#",
                  target: "_blank",
                  title: _ctx.t("plugins.generic.plagiarism.similarity.action.launch.viewer.title")
                }, [
                  vue.createElementVNode("img", {
                    alt: _ctx.t("plugins.generic.plagiarism.similarity.match.title"),
                    src: fileStatus.value.ithenticateLogo
                  }, null, 8, _hoisted_3)
                ], 8, _hoisted_2),
                vue.createElementVNode("span", null, vue.toDisplayString(fileStatus.value.ithenticateSimilarityResult) + " % ", 1)
              ])) : ((_b = fileStatus.value) == null ? void 0 : _b.ithenticateId) ? (vue.openBlock(), vue.createElementBlock("span", _hoisted_4, [
                vue.createVNode(_component_icon, {
                  icon: "InProgress",
                  class: "h-6 w-6"
                }),
                vue.createElementVNode("span", _hoisted_5, [
                  !((_c = fileStatus.value) == null ? void 0 : _c.ithenticateSimilarityScheduled) ? (vue.openBlock(), vue.createElementBlock("span", _hoisted_6, vue.toDisplayString(_ctx.t("plugins.generic.plagiarism.file.statusText.waitingOnReportSchedule")), 1)) : (vue.openBlock(), vue.createElementBlock("span", _hoisted_7, vue.toDisplayString(_ctx.t("plugins.generic.plagiarism.file.statusText.reportScheduleCompletd")) + " " + vue.toDisplayString(_ctx.t("plugins.generic.plagiarism.file.statusText.waitingOnSimilarityScore")), 1))
                ])
              ])) : vue.createCommentVNode("", true)
            ];
          }),
          _: 1
        });
      };
    }
  };
  const ithenticateSimilarityScoreCell = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-4a9363b4"]]);
  pkp.registry.registerComponent("ithenticateSimilarityScoreCell", ithenticateSimilarityScoreCell);
  const { useLocalize } = pkp.modules.useLocalize;
  const { useApp } = pkp.modules.useApp;
  const { useNotify } = pkp.modules.useNotify;
  const { useCurrentUser } = pkp.modules.useCurrentUser;
  function runPlagiarismAction(piniaContext, stageNamespace) {
    const dashboardStore = pkp.registry.getPiniaStore("dashboard");
    if (dashboardStore.dashboardPage !== "editorialDashboard") {
      return;
    }
    const { useUrl } = pkp.modules.useUrl;
    const { useFetch } = pkp.modules.useFetch;
    const { t } = useLocalize();
    const { isOPS } = useApp();
    const fileStore = piniaContext.store;
    const { submission, submissionStageId } = fileStore.props;
    const { notify } = useNotify();
    const eventSource = vue.ref(null);
    const timeoutId = vue.ref(null);
    const maxDuration = vue.ref(600);
    const ithenticateRequestParams = vue.computed(() => {
      var _a, _b;
      const fileIds = isOPS() ? ((_a = fileStore == null ? void 0 : fileStore.galleys) == null ? void 0 : _a.map((galley) => galley.file.id)) || [] : ((_b = fileStore == null ? void 0 : fileStore.files) == null ? void 0 : _b.map((file) => file.id)) || [];
      return {
        fileIds,
        submissionId: submission.id,
        stageId: isOPS() ? pkp.const.WORKFLOW_STAGE_ID_PRODUCTION : submissionStageId
      };
    });
    const { apiUrl } = useUrl(`submissions/${submission.id}/plagiarism/status`);
    const {
      fetch: fetchIthenticateStatus,
      data: ithenticateStatus
    } = useFetch(
      apiUrl,
      {
        method: "POST",
        body: ithenticateRequestParams
      }
    );
    vue.watch(
      ithenticateRequestParams,
      async (newRequestParams) => {
        var _a;
        if ((_a = newRequestParams == null ? void 0 : newRequestParams.fileIds) == null ? void 0 : _a.length) {
          try {
            await fetchIthenticateStatus();
            fileStore.ithenticateStatus = ithenticateStatus;
            if (ithenticateStatus.value) {
              if (shouldStreamPlagiarismResults(ithenticateStatus.value) && !eventSource.value) {
                streamPlagiarismResults(newRequestParams);
              }
            }
          } catch (error) {
            console.error("Error fetching ithenticateStatus:", error);
          }
        }
      },
      { deep: true }
    );
    if (isOPS()) {
      (async () => {
        var _a, _b;
        try {
          await fetchIthenticateStatus();
          fileStore.ithenticateStatus = ithenticateStatus;
          if (ithenticateStatus.value && ((_b = (_a = ithenticateRequestParams.value) == null ? void 0 : _a.fileIds) == null ? void 0 : _b.length)) {
            if (shouldStreamPlagiarismResults(ithenticateStatus.value) && !eventSource.value) {
              streamPlagiarismResults(ithenticateRequestParams.value);
            }
          }
        } catch (error) {
          console.error("Error in initial OPS fetch:", error);
        }
      })();
    }
    fileStore.extender.extendFn("getColumns", (columns, args) => {
      const newColumns = [...columns];
      newColumns.splice(newColumns.length - 1, 0, {
        header: t("plugins.generic.plagiarism.similarity.match.title"),
        component: "ithenticateSimilarityScoreCell",
        props: {
          fileStageNamespace: stageNamespace
        }
      });
      return newColumns;
    });
    function getLabel(userStatus, submissionStatus, fileStatus) {
      if (!fileStatus.ithenticateId) {
        return t("plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.title");
      }
      if (fileStatus.ithenticateId && !fileStatus.ithenticateSimilarityScheduled) {
        return t("plugins.generic.plagiarism.similarity.action.generateReport.title");
      }
      return t("plugins.generic.plagiarism.similarity.action.refreshReport.title");
    }
    function getConfirmationMessage(fileStatus) {
      if (!fileStatus.ithenticateId) {
        return t("plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.confirmation");
      }
      if (!fileStatus.ithenticateSimilarityScheduled) {
        return t("plugins.generic.plagiarism.similarity.action.generateReport.confirmation");
      }
      return t("plugins.generic.plagiarism.similarity.action.refreshReport.confirmation");
    }
    function getActionUrl(fileStatus) {
      if (!fileStatus.ithenticateId) {
        return fileStatus.ithenticateUploadUrl;
      }
      if (!fileStatus.ithenticateSimilarityScheduled) {
        return fileStatus.ithenticateReportScheduleUrl;
      }
      return fileStatus.ithenticateReportRefreshUrl;
    }
    function isEulaConfirmationRequired(contextStatus, submissionStatus, userStatus) {
      if (!contextStatus.eulaRequired) {
        return false;
      }
      if (!submissionStatus.ithenticateEulaVersion) {
        return true;
      }
      if (!userStatus.ithenticateEulaVersion) {
        return true;
      }
      if (submissionStatus.ithenticateEulaVersion !== userStatus.ithenticateEulaVersion) {
        return true;
      }
      return false;
    }
    async function executePlagiarismAction(fileStatus) {
      const actionUrl = getActionUrl(fileStatus);
      const {
        fetch: executeIthenticateAction,
        data: ithenticateActionData
      } = useFetch(actionUrl);
      await executeIthenticateAction();
      return ithenticateActionData;
    }
    function shouldStreamPlagiarismResults(status) {
      if (!(status == null ? void 0 : status.files)) {
        return false;
      }
      return Object.values(status.files).some(
        (file) => file.ithenticateId !== null && file.ithenticateSimilarityResult === null
      );
    }
    fileStore.extender.extendFn("getItemActions", (originalResult, args) => {
      var _a, _b, _c;
      const submission2 = fileStore.props.submission;
      const submissionFile = isOPS() ? args.galley.file : args.file;
      if (!isOPS() && submission2.stageId !== submissionStageId) {
        return [...originalResult];
      }
      if (ithenticateStatus.value) {
        const fileStatus = deduceFileStatus(submissionFile, ithenticateStatus.value);
        const userStatus = (_a = ithenticateStatus.value) == null ? void 0 : _a.user;
        const submissionStatus = (_b = ithenticateStatus.value) == null ? void 0 : _b.submission;
        const contextStatus = (_c = ithenticateStatus.value) == null ? void 0 : _c.context;
        if (!fileStatus) {
          return [...originalResult];
        }
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
            actionFn: (args2) => {
              if (!fileStatus.ithenticateId && isEulaConfirmationRequired(contextStatus, submissionStatus, userStatus)) {
                const { useLegacyGridUrl } = pkp.modules.useLegacyGridUrl;
                const { openLegacyModal } = useLegacyGridUrl({
                  component: "plugins.generic.plagiarism.controllers.PlagiarismIthenticateHandler",
                  op: "confirmEula",
                  params: {
                    submissionId: submissionFile.submissionId,
                    submissionFileId: submissionFile.id,
                    stageId: submission2.stageId
                  }
                });
                openLegacyModal(
                  {
                    title: t("plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.title")
                  },
                  async () => {
                    await fetchIthenticateStatus();
                    if (shouldStreamPlagiarismResults(ithenticateStatus.value) && !eventSource.value) {
                      streamPlagiarismResults(ithenticateRequestParams.value);
                    }
                  }
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
                    label: t("common.yes"),
                    isPrimary: true,
                    callback: async (close) => {
                      var _a2, _b2;
                      close();
                      const ithenticateActionData = await executePlagiarismAction(fileStatus);
                      if ((_a2 = ithenticateActionData.value) == null ? void 0 : _a2.content) {
                        notify(
                          ithenticateActionData.value.content,
                          ((_b2 = ithenticateActionData.value) == null ? void 0 : _b2.status) ? "success" : "warning"
                        );
                      }
                      await fetchIthenticateStatus();
                      if (shouldStreamPlagiarismResults(ithenticateStatus.value) && !eventSource.value) {
                        streamPlagiarismResults(ithenticateRequestParams.value);
                      }
                    }
                  },
                  {
                    label: t("common.no"),
                    isWarnable: true,
                    callback: (close) => {
                      close();
                    }
                  }
                ]
              });
            }
          }
        ];
      }
      return [...originalResult];
    });
    function streamPlagiarismResults(params) {
      var _a;
      if (eventSource.value) {
        eventSource.value.close();
        eventSource.value = null;
      }
      if (timeoutId.value) {
        clearTimeout(timeoutId.value);
        timeoutId.value = null;
      }
      if (!((_a = params == null ? void 0 : params.fileIds) == null ? void 0 : _a.length)) {
        return;
      }
      const queryParams = new URLSearchParams({
        submissionId: params.submissionId,
        stageId: params.stageId,
        fileIds: params.fileIds.join(",")
      });
      const streamUrl = `${apiUrl.value}/stream?${queryParams.toString()}`;
      let lastMessageTime = Date.now();
      let pollingInterval = null;
      let retryCount = 0;
      const maxRetries = 3;
      try {
        eventSource.value = new EventSource(streamUrl);
        const fallbackTimeout = setTimeout(() => {
          if (Date.now() - lastMessageTime > 3e4) {
            console.log("No SSE messages received, falling back to polling");
            if (eventSource.value) {
              eventSource.value.close();
              eventSource.value = null;
            }
            pollingInterval = setInterval(async () => {
              try {
                if (!fileStore.ithenticateStatus.value && retryCount < maxRetries) {
                  await fetchIthenticateStatus();
                  retryCount++;
                } else if (shouldStreamPlagiarismResults(ithenticateStatus.value)) {
                  await fetchIthenticateStatus();
                  retryCount = 0;
                } else {
                  clearInterval(pollingInterval);
                  pollingInterval = null;
                  retryCount = 0;
                }
              } catch (error) {
                retryCount++;
                if (retryCount >= maxRetries) {
                  clearInterval(pollingInterval);
                  pollingInterval = null;
                  retryCount = 0;
                }
              }
            }, 1e4);
          }
        }, 3e4);
        eventSource.value.onmessage = (event) => {
          var _a2;
          lastMessageTime = Date.now();
          try {
            const data = JSON.parse(event.data);
            if (data.maxDuration) {
              maxDuration.value = Number(data.maxDuration);
              if (timeoutId.value) {
                clearTimeout(timeoutId.value);
                timeoutId.value = null;
              }
              timeoutId.value = setTimeout(() => {
                console.log(`Client-side ${maxDuration.value}-second limit reached at:`, (/* @__PURE__ */ new Date()).toISOString());
                if (eventSource.value) {
                  eventSource.value.close();
                  eventSource.value = null;
                }
                timeoutId.value = null;
                clearTimeout(fallbackTimeout);
                if (pollingInterval) {
                  clearInterval(pollingInterval);
                }
                fetchIthenticateStatus();
              }, maxDuration.value * 1e3);
              return;
            }
            if (data && fileStore.ithenticateStatus) {
              const oldFiles = { ...((_a2 = fileStore.ithenticateStatus.value) == null ? void 0 : _a2.files) || {} };
              fileStore.ithenticateStatus.value = {
                ...fileStore.ithenticateStatus.value,
                ...data
              };
              let hasNewSimilarityResult = false, hasNewReportScheduled = false;
              if (data.files && oldFiles) {
                Object.entries(data.files).forEach(([fileId, newFile]) => {
                  const oldFile = oldFiles[fileId];
                  if (oldFile && oldFile.ithenticateId !== null) {
                    if (oldFile.ithenticateSimilarityResult === null && newFile.ithenticateSimilarityResult !== null) {
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
                  t("plugins.generic.plagiarism.action.scheduleSimilarityReport.success"),
                  "success"
                );
              }
              if (hasNewSimilarityResult) {
                notify(
                  t("plugins.generic.plagiarism.action.refreshSimilarityResult.success"),
                  "success"
                );
              }
              if (hasNewSimilarityResult || hasNewReportScheduled) {
                fetchIthenticateStatus();
              }
              if (!shouldStreamPlagiarismResults(fileStore.ithenticateStatus.value)) {
                if (eventSource.value) {
                  eventSource.value.close();
                  eventSource.value = null;
                }
                if (timeoutId.value) {
                  clearTimeout(timeoutId.value);
                  timeoutId.value = null;
                }
                clearTimeout(fallbackTimeout);
                if (pollingInterval) {
                  clearInterval(pollingInterval);
                }
                fetchIthenticateStatus();
                return;
              }
            }
          } catch (e) {
            console.error("Error parsing EventSource data:", e);
          }
        };
        eventSource.value.addEventListener("stream_end", () => {
          if (eventSource.value) {
            eventSource.value.close();
            eventSource.value = null;
          }
          if (timeoutId.value) {
            clearTimeout(timeoutId.value);
            timeoutId.value = null;
          }
          clearTimeout(fallbackTimeout);
          if (pollingInterval) {
            clearInterval(pollingInterval);
          }
          fetchIthenticateStatus();
        });
        eventSource.value.onerror = (event) => {
          if (eventSource.value) {
            eventSource.value.close();
            eventSource.value = null;
          }
          if (timeoutId.value) {
            clearTimeout(timeoutId.value);
            timeoutId.value = null;
          }
          clearTimeout(fallbackTimeout);
          pollingInterval = setInterval(() => {
            if (shouldStreamPlagiarismResults(fileStore.ithenticateStatus.value)) {
              fetchIthenticateStatus();
            } else {
              clearInterval(pollingInterval);
            }
          }, 1e4);
          fetchIthenticateStatus();
        };
        eventSource.value.onopen = () => {
          console.log("EventSource connection opened at:", (/* @__PURE__ */ new Date()).toISOString());
        };
      } catch (e) {
        console.error("Failed to initialize EventSource:", e);
        pollingInterval = setInterval(() => {
          if (shouldStreamPlagiarismResults(fileStore.ithenticateStatus.value)) {
            fetchIthenticateStatus();
          } else {
            clearInterval(pollingInterval);
          }
        }, 1e4);
      }
    }
    vue.onUnmounted(() => {
      if (eventSource.value) {
        eventSource.value.close();
        eventSource.value = null;
      }
      if (timeoutId.value) {
        clearTimeout(timeoutId.value);
        timeoutId.value = null;
      }
    });
    window.addEventListener("beforeunload", () => {
      if (eventSource.value) {
        eventSource.value.close();
        eventSource.value = null;
      }
      if (timeoutId.value) {
        clearTimeout(timeoutId.value);
        timeoutId.value = null;
      }
    });
  }
  pkp.registry.storeExtend("fileManager_SUBMISSION_FILES", (piniaContext) => {
    runPlagiarismAction(piniaContext, "fileManager_SUBMISSION_FILES");
  });
  pkp.registry.storeExtend("fileManager_EDITOR_REVIEW_FILES", (piniaContext) => {
    runPlagiarismAction(piniaContext, "fileManager_EDITOR_REVIEW_FILES");
  });
  pkp.registry.storeExtend("galleyManager", (piniaContext) => {
    const { isOPS } = useApp();
    if (!isOPS()) {
      return;
    }
    runPlagiarismAction(piniaContext, "galleyManager");
  });
})(pkp.modules.vue);
