export function deduceFileStatus(submissionFile, ithenticateDataStatus)
{
    const fileId =  submissionFile.id;
    const sourceSubmissionFileId = submissionFile.sourceSubmissionFileId;

    if (ithenticateDataStatus?.files?.[fileId]?.ithenticateId) {
        return ithenticateDataStatus?.files?.[fileId];
    }

    const sourceSubmissionFile = ithenticateDataStatus?.files?.[sourceSubmissionFileId];

    if (sourceSubmissionFile && sourceSubmissionFile.fileId === submissionFile.fileId) {
        return sourceSubmissionFile;
    }

    return ithenticateDataStatus?.files?.[fileId];
}