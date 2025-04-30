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
      const { useUrl } = pkp.modules.useUrl;
      const { useFetch } = pkp.modules.useFetch;
      const props = __props;
      const { t: t2, localize: localize2 } = useLocalize2();
      const { apiUrl } = useUrl(`submissions/${props.file.submissionId}/files/${props.file.id}/plagiarism/status`);
      const {
        data: plagiarismFileStatus,
        fetch: fetchPlagiarismFileStatus
      } = useFetch(apiUrl, {
        query: {
          stageId: pkp.const.WORKFLOW_STAGE_ID_SUBMISSION
        }
      });
      vue.onMounted(async () => {
        await fetchPlagiarismFileStatus();
      });
      const fileStatus = vue.computed(() => plagiarismFileStatus.value || {});
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
  const ithenticateSimilarityScoreCell = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-7c161276"]]);
  pkp.registry.registerComponent("ithenticateSimilarityScoreCell", ithenticateSimilarityScoreCell);
  const { useLocalize } = pkp.modules.useLocalize;
  const { t, localize } = useLocalize();
  function addIthenticateColumn(columns, args) {
    const newColumns = [...columns];
    newColumns.splice(newColumns.length - 1, 0, {
      header: "iThenticate",
      component: "ithenticateSimilarityScoreCell",
      props: {
        submission: {}
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
    (originalResult, args) => {
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
                stageId: 1
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
