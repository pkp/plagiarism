(function(vue) {
  "use strict";
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
  const _hoisted_2 = ["href", "target", "title"];
  const _hoisted_3 = ["alt", "src"];
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
        var _a, _b, _c, _d, _e, _f, _g, _h;
        const submissionFile = isOPS() ? props.galley.file : props.file;
        const fileId = submissionFile.id;
        const sourceSubmissionFileId = submissionFile.sourceSubmissionFileId;
        const status = (((_b = (_a = fileStore == null ? void 0 : fileStore.ithenticateStatus) == null ? void 0 : _a.files) == null ? void 0 : _b[fileId].ithenticateId) ? (_d = (_c = fileStore == null ? void 0 : fileStore.ithenticateStatus) == null ? void 0 : _c.files) == null ? void 0 : _d[fileId] : ((_f = (_e = fileStore == null ? void 0 : fileStore.ithenticateStatus) == null ? void 0 : _e.files) == null ? void 0 : _f[sourceSubmissionFileId]) ?? ((_h = (_g = fileStore == null ? void 0 : fileStore.ithenticateStatus) == null ? void 0 : _g.files) == null ? void 0 : _h[fileId])) || {};
        return status;
      });
      return (_ctx, _cache) => {
        const _component_PkpTableCell = vue.resolveComponent("PkpTableCell");
        return vue.openBlock(), vue.createBlock(_component_PkpTableCell, null, {
          default: vue.withCtx(() => {
            var _a;
            return [
              ((_a = fileStatus.value) == null ? void 0 : _a.ithenticateSimilarityResult) ? (vue.openBlock(), vue.createElementBlock("span", _hoisted_1, [
                vue.createElementVNode("a", {
                  href: fileStatus.value.ithenticateViewerUrl ?? "#",
                  target: _ctx._blank,
                  title: _ctx.t("plugins.generic.plagiarism.similarity.action.launch.viewer.title")
                }, [
                  vue.createElementVNode("img", {
                    alt: _ctx.t("plugins.generic.plagiarism.similarity.match.title"),
                    src: fileStatus.value.ithenticateLogo
                  }, null, 8, _hoisted_3)
                ], 8, _hoisted_2),
                vue.createElementVNode("span", null, vue.toDisplayString(fileStatus.value.ithenticateSimilarityResult) + " % ", 1)
              ])) : vue.createCommentVNode("", true)
            ];
          }),
          _: 1
        });
      };
    }
  };
  const ithenticateSimilarityScoreCell = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-705f7f5e"]]);
  pkp.registry.registerComponent("ithenticateSimilarityScoreCell", ithenticateSimilarityScoreCell);
  const { useLocalize } = pkp.modules.useLocalize;
  const { useApp } = pkp.modules.useApp;
  function runPlagiarismAction(piniaContext, stageNamespace) {
    const { useUrl } = pkp.modules.useUrl;
    const { useFetch } = pkp.modules.useFetch;
    const { t } = useLocalize();
    const { isOPS } = useApp();
    const fileStore = piniaContext.store;
    const { submission, submissionStageId } = fileStore.props;
    const ithenticateQueryParams = vue.computed(() => {
      var _a, _b;
      const fileIds = isOPS() ? ((_a = fileStore == null ? void 0 : fileStore.galleys) == null ? void 0 : _a.map((galley) => galley.file.id)) || [] : ((_b = fileStore == null ? void 0 : fileStore.files) == null ? void 0 : _b.map((file) => file.id)) || [];
      return {
        fileIds,
        submissionId: submission.id,
        stageId: submissionStageId
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
        body: ithenticateQueryParams
      }
    );
    vue.watch(ithenticateQueryParams, (newQueryParams) => {
      var _a;
      if ((_a = newQueryParams == null ? void 0 : newQueryParams.fileIds) == null ? void 0 : _a.length) {
        fetchIthenticateStatus();
      }
    });
    if (isOPS()) {
      fetchIthenticateStatus();
    }
    fileStore.ithenticateStatus = ithenticateStatus;
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
      if (!userStatus.ithenticateEulaVersion || !submissionStatus.ithenticateEulaVersion) {
        return t("plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.title");
      }
      if (!fileStatus.ithenticateId) {
        return t("plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.title");
      }
      if (!fileStatus.ithenticateSimilarityScheduled) {
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
      var _a;
      const actionUrl = getActionUrl(fileStatus);
      const { apiUrl: apiUrl2 } = useUrl(actionUrl);
      const {
        fetch: executeIthenticateAction,
        data: ithenticateActionData
      } = useFetch(apiUrl2);
      await executeIthenticateAction();
      return (_a = ithenticateActionData.value) == null ? void 0 : _a.status;
    }
    fileStore.extender.extendFn("getItemActions", (originalResult, args) => {
      var _a, _b, _c, _d, _e, _f, _g, _h, _i, _j, _k, _l;
      const submission2 = fileStore.props.submission;
      const submissionFile = isOPS() ? args.galley.file : args.file;
      if (!isOPS() && submission2.stageId !== submissionStageId) {
        return [...originalResult];
      }
      if (ithenticateStatus.value) {
        const fileId = submissionFile.id;
        const sourceSubmissionFileId = submissionFile.sourceSubmissionFileId;
        const fileStatus = ((_c = (_b = (_a = ithenticateStatus.value) == null ? void 0 : _a.files) == null ? void 0 : _b[fileId]) == null ? void 0 : _c.ithenticateId) ? (_e = (_d = ithenticateStatus.value) == null ? void 0 : _d.files) == null ? void 0 : _e[fileId] : ((_g = (_f = ithenticateStatus.value) == null ? void 0 : _f.files) == null ? void 0 : _g[sourceSubmissionFileId]) ?? ((_i = (_h = ithenticateStatus.value) == null ? void 0 : _h.files) == null ? void 0 : _i[fileId]);
        const userStatus = (_j = ithenticateStatus.value) == null ? void 0 : _j.user;
        const submissionStatus = (_k = ithenticateStatus.value) == null ? void 0 : _k.submission;
        const contextStatus = (_l = ithenticateStatus.value) == null ? void 0 : _l.context;
        if (!fileStatus.ithenticateUploadAllowed) {
          return [...originalResult];
        }
        const matchAllowedRoles = userStatus.ithenticateActionAllowedRoles.filter((role) => window.pkp.currentUser.roles.includes(role));
        if (matchAllowedRoles.length === 0) {
          return [...originalResult];
        }
        return [
          ...originalResult,
          {
            label: getLabel(userStatus, submissionStatus, fileStatus),
            name: "conductPlagiarismCheck",
            icon: "Globe",
            actionFn: (args2) => {
              if (isEulaConfirmationRequired(contextStatus, submissionStatus, userStatus)) {
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
                  () => {
                    fetchIthenticateStatus();
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
                      close();
                      const status = await executePlagiarismAction(fileStatus);
                      if (status) {
                        fetchIthenticateStatus();
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
