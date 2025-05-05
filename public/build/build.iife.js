(function(vue) {
  "use strict";
  const _export_sfc = (sfc, props) => {
    const target = sfc.__vccOpts || sfc;
    for (const [key, val] of props) {
      target[key] = val;
    }
    return target;
  };
  const _hoisted_1 = { class: "plagiarism-similarity-score" };
  const _hoisted_2 = ["href", "target"];
  const _hoisted_3 = ["src"];
  const _sfc_main = {
    __name: "ithenticateSimilarityScoreCell",
    props: {
      file: { type: Object, required: true },
      submission: { type: Object, required: false }
    },
    setup(__props) {
      const { useLocalize: useLocalize2 } = pkp.modules.useLocalize;
      const { useUrl: useUrl2 } = pkp.modules.useUrl;
      const { useFetch: useFetch2 } = pkp.modules.useFetch;
      const props = __props;
      const { t: t2, localize: localize2 } = useLocalize2();
      const { apiUrl } = useUrl2(`submissions/${props.file.submissionId}/files/${props.file.id}/plagiarism/status`);
      const {
        data: plagiarismFileStatus,
        fetch: fetchPlagiarismFileStatus2
      } = useFetch2(apiUrl, {
        query: {
          stageId: props.submission.stageId
        }
      });
      vue.onMounted(async () => {
        await fetchPlagiarismFileStatus2();
      });
      const fileStatus = vue.computed(() => {
        var _a;
        return ((_a = plagiarismFileStatus.value) == null ? void 0 : _a.file) || {};
      });
      return (_ctx, _cache) => {
        const _component_PkpTableCell = vue.resolveComponent("PkpTableCell");
        return vue.openBlock(), vue.createBlock(_component_PkpTableCell, null, {
          default: vue.withCtx(() => [
            vue.createElementVNode("span", _hoisted_1, [
              vue.createElementVNode("a", {
                href: fileStatus.value.ithenticateViewerUrl ?? "#",
                target: _ctx._blank
              }, [
                vue.createElementVNode("img", {
                  src: fileStatus.value.ithenticateLogo
                }, null, 8, _hoisted_3)
              ], 8, _hoisted_2),
              vue.createElementVNode("span", null, vue.toDisplayString(fileStatus.value.ithenticateSimilarityResult) + "% ", 1)
            ])
          ]),
          _: 1
        });
      };
    }
  };
  const ithenticateSimilarityScoreCell = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-330c93da"]]);
  pkp.registry.registerComponent("ithenticateSimilarityScoreCell", ithenticateSimilarityScoreCell);
  const { useLocalize } = pkp.modules.useLocalize;
  const { useUrl } = pkp.modules.useUrl;
  const { useFetch } = pkp.modules.useFetch;
  const { t, localize } = useLocalize();
  const plagiarismStatusMap = /* @__PURE__ */ new Map();
  async function fetchPlagiarismFileStatus(submissionId, fileId, stageId) {
    const { apiUrl } = useUrl(`submissions/${submissionId}/files/${fileId}/plagiarism/status`);
    const {
      data: plagiarismFileStatus,
      fetch: fetchPlagiarismFileStatus2
    } = useFetch(apiUrl, {
      query: {
        stageId
      }
    });
    await fetchPlagiarismFileStatus2();
    return plagiarismFileStatus;
  }
  function addIthenticateColumn(columns, args) {
    const newColumns = [...columns];
    newColumns.splice(newColumns.length - 1, 0, {
      header: "iThenticate",
      component: "ithenticateSimilarityScoreCell",
      props: {
        submission: args.props.submission
      }
    });
    return newColumns;
  }
  pkp.registry.storeExtendFn(
    "fileManager_SUBMISSION_FILES",
    "getColumns",
    addIthenticateColumn
  );
  pkp.registry.storeExtendFn(
    "fileManager_EDITOR_REVIEW_FILES",
    "getColumns",
    addIthenticateColumn
  );
  pkp.registry.storeExtendFn(
    "fileManager_SUBMISSION_FILES",
    "getItemActions",
    async (originalResult, args, context) => {
      var _a;
      const submissionFile = args.file;
      const submission = context.props.submission;
      const cacheKey = `${submission.id}-${submissionFile.id}`;
      if (!plagiarismStatusMap.has(cacheKey)) {
        const status = await fetchPlagiarismFileStatus(submission.id, submissionFile.id, submission.stageId);
        plagiarismStatusMap.set(cacheKey, status.value);
      }
      const plagiarismStatus = (_a = plagiarismStatusMap.get(cacheKey)) == null ? void 0 : _a.value;
      console.log("plagiarismStatus");
      console.log(plagiarismStatus);
      return [
        ...originalResult,
        {
          label: t("plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.title"),
          name: "conductPlagiarismCheck",
          icon: "Globe",
          actionFn: ({ file }) => {
            const { useLegacyGridUrl } = pkp.modules.useLegacyGridUrl;
            const { openLegacyModal } = useLegacyGridUrl({
              component: "plugins.generic.plagiarism.controllers.PlagiarismIthenticateHandler",
              op: "acceptEulaAndExecuteIntendedAction",
              params: {
                submissionId: file.submissionId,
                submissionFileId: file.id,
                stageId: submission.stageId
              }
            });
            openLegacyModal(
              {
                title: t("plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.title")
              },
              () => {
                console.log("EUAL confirmed");
              }
            );
          }
        }
      ];
    }
  );
})(pkp.modules.vue);
