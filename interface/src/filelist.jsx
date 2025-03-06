import React, { useState } from 'react';
import { Modal } from './modal';

export const FileList = ({ files, isLoading, onDelete, onRename }) => {
  const [editingId, setEditingId] = useState(null);
  const [newFileName, setNewFileName] = useState('');
  const [selectedFiles, setSelectedFiles] = useState([]);
  const [deleteModal, setDeleteModal] = useState({
    isOpen: false,
    fileId: null,
    fileName: '',
    isMultiple: false
  });

  const startEditing = (file) => {
    setEditingId(file.id);
    setNewFileName(file.original_name);
  };

  const cancelEditing = () => {
    setEditingId(null);
    setNewFileName('');
  };

  const saveFileName = (fileId) => {
    if (newFileName.trim()) {
      onRename(fileId, newFileName.trim());
      cancelEditing();
    }
  };

  const openDeleteModal = (fileId, fileName) => {
    setDeleteModal({
      isOpen: true,
      fileId,
      fileName,
      isMultiple: false
    });
  };

  const openMultiDeleteModal = () => {
    setDeleteModal({
      isOpen: true,
      fileId: null,
      fileName: `${selectedFiles.length} файл`,
      isMultiple: true
    });
  };

  const closeDeleteModal = () => {
    setDeleteModal({
      isOpen: false,
      fileId: null,
      fileName: '',
      isMultiple: false
    });
  };

  const confirmDelete = () => {
    if (deleteModal.isMultiple) {
      selectedFiles.forEach(fileId => {
        onDelete(fileId);
      });
      setSelectedFiles([]);
    } else {
      onDelete(deleteModal.fileId);
    }
    closeDeleteModal();
  };

  const toggleFileSelection = (fileId) => {
    setSelectedFiles(prevSelected => {
      if (prevSelected.includes(fileId)) {
        return prevSelected.filter(id => id !== fileId);
      } else {
        return [...prevSelected, fileId];
      }
    });
  };

  const toggleSelectAll = () => {
    if (selectedFiles.length === files.length) {
      setSelectedFiles([]);
    } else {
      setSelectedFiles(files.map(file => file.id));
    }
  };

  const getFileIcon = (category) => {
    switch (category) {
      case 'image':
        return (
          <svg className="w-6 h-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
          </svg>
        );
      case 'video':
        return (
          <svg className="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
          </svg>
        );
      case 'document':
        return (
          <svg className="w-6 h-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
          </svg>
        );
      default:
        return (
          <svg className="w-6 h-6 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
          </svg>
        );
    }
  };

  const formatFileSize = (sizeInBytes) => {
    if (sizeInBytes < 1024) return `${sizeInBytes} B`;
    if (sizeInBytes < 1024 * 1024) return `${(sizeInBytes / 1024).toFixed(2)} KB`;
    if (sizeInBytes < 1024 * 1024 * 1024) return `${(sizeInBytes / (1024 * 1024)).toFixed(2)} MB`;
    return `${(sizeInBytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
  };

  const formatDate = (dateString) => {
    const date = new Date(dateString);
    return date.toLocaleString();
  };

  if (isLoading) {
    return (
      <div className="bg-white p-6 rounded-lg shadow-md">
        <h2 className="text-xl font-semibold mb-4">Файлууд</h2>
        <div className="flex justify-center items-center p-4">
          <svg className="animate-spin h-5 w-5 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <span className="ml-2">Үйлдэл явагдаж байна...</span>
        </div>
      </div>
    );
  }

  return (
    <>
      <div className="bg-white p-6 rounded-lg shadow-md">
        <div className="flex justify-between items-center mb-4">
          <h2 className="text-xl font-semibold">Файлууд</h2>
          {selectedFiles.length > 0 && (
            <button
              onClick={openMultiDeleteModal}
              className="bg-red-500 hover:bg-red-600 text-white py-2 px-4 rounded-md text-sm flex items-center"
            >
              <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
              </svg>
              Сонгосон файлуудыг устгах ({selectedFiles.length})
            </button>
          )}
        </div>
        
        {files.length === 0 ? (
          <div className="text-center py-8 text-gray-500">
            Файлын сан хоосон байна.
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th scope="col" className="px-4 py-3">
                    <div className="flex items-center">
                      <input
                        type="checkbox"
                        className="h-4 w-4 text-blue-600 rounded"
                        checked={selectedFiles.length === files.length && files.length > 0}
                        onChange={toggleSelectAll}
                      />
                    </div>
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Нэр</th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Файлын өргөтгөл</th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Хэмжээ</th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Төлөв</th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Үйлдэл</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {files.map(file => (
                  <tr key={file.id} className={selectedFiles.includes(file.id) ? "bg-blue-50" : ""}>
                    <td className="px-4 py-4 whitespace-nowrap">
                      <div className="flex items-center">
                        <input
                          type="checkbox"
                          className="h-4 w-4 text-blue-600 rounded"
                          checked={selectedFiles.includes(file.id)}
                          onChange={() => toggleFileSelection(file.id)}
                        />
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="flex items-center">
                        {getFileIcon(file.category)}
                        <div className="ml-2 w-44">
                          {editingId === file.id ? (
                            <input
                              type="text"
                              className="px-2 py-1 outline-none text-sm border-gray-300 rounded-md shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50"
                              value={newFileName}
                              onChange={(e) => setNewFileName(e.target.value)}
                              autoFocus
                            />
                          ) : (
                            <div className="text-sm font-medium text-gray-900 truncate">{file.original_name}</div>
                          )}
                        </div>
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-lime-300 text-gray-600">
                        {file.mime_type}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      {formatFileSize(file.file_size)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      {formatDate(file.created_at)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                      {editingId === file.id ? (
                        <div className="flex space-x-2">
                          <button
                            onClick={() => saveFileName(file.id)}
                            className="cursor-pointer bg-green-500 rounded py-1 px-2 text-sm text-white"
                          >
                            Хадгалах
                          </button>
                          <button
                            onClick={cancelEditing}
                            className="cursor-pointer bg-gray-500 rounded py-1 px-2 text-sm text-white"
                          >
                            Болих
                          </button>
                        </div>
                      ) : (
                        <div className="flex space-x-2">
                          <button
                            onClick={() => startEditing(file)}
                            className="cursor-pointer bg-amber-500 rounded py-1 px-2 text-sm text-white"
                          >
                            Нэр өөрчлөх
                          </button>
                          <button
                            onClick={() => openDeleteModal(file.id, file.original_name)}
                            className="cursor-pointer bg-red-500 rounded py-1 px-2 text-sm text-white"
                          >
                            Устгах
                          </button>
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <Modal
        isOpen={deleteModal.isOpen}
        onClose={closeDeleteModal}
        onConfirm={confirmDelete}
        fileName={deleteModal.fileName}
        isMultiple={deleteModal.isMultiple}
      />
    </>
  );
}