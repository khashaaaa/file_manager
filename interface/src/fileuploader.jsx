import React, { useState, useRef } from 'react';
import axios from 'axios';

export const FileUploader = ({ apiUrl, onUploadComplete }) => {
  const [files, setFiles] = useState([]);
  const [uploading, setUploading] = useState(false);
  const [uploadProgress, setUploadProgress] = useState(0);
  const [uploadResults, setUploadResults] = useState([]);
  const fileInputRef = useRef(null);

  const handleFileChange = (e) => {
    if (e.target.files.length > 0) {
      setFiles(Array.from(e.target.files));
      setUploadProgress(0);
      setUploadResults([]);
    }
  };

  const resetUploader = () => {
    setFiles([]);
    setUploadProgress(0);
    setUploadResults([]);
    if (fileInputRef.current) {
      fileInputRef.current.value = '';
    }
  };

  const uploadFiles = async () => {
    if (files.length === 0) return;
    
    setUploading(true);
    const formData = new FormData();
    
    files.forEach((file, index) => {
      formData.append(`file[${index}]`, file);
    });
  
    setUploadProgress(0);
    
    try {
      const response = await axios.post(`${apiUrl}/files`, formData, {
        headers: {
          'Content-Type': 'multipart/form-data'
        },
        onUploadProgress: (progressEvent) => {
          const percentCompleted = Math.round(
            (progressEvent.loaded * 100) / progressEvent.total
          );
          setUploadProgress(percentCompleted);
        }
      });
  
      setUploadProgress(100);
  
      if (response.data?.results) {
        setUploadResults(response.data.results);
      } else {
        setUploadResults(files.map(file => ({ name: file.name, status: 'success' })));
      }
  
      onUploadComplete();
      setTimeout(resetUploader, 2000);
    } catch (error) {
      console.error('Алдааны мэдээлэл:', error);
  
      const errorResults = files.map(file => ({
        name: file.name,
        error: error.response?.data?.error || 'Failed to upload files'
      }));
  
      setUploadResults(errorResults);
    } finally {
      setUploading(false);
    }
  };
  

  const totalSize = files.reduce((total, file) => total + file.size, 0);
  const formattedTotalSize = (totalSize / (1024 * 1024)).toFixed(2);

  return (
    <div className="bg-white p-6 rounded-lg shadow-md mb-6">
      <h2 className="text-xl font-semibold mb-4">Файлууд хуулах</h2>
      
      <div className="mb-4 w-64">
        <input
          ref={fileInputRef}
          type="file"
          multiple
          onChange={handleFileChange}
          className="cursor-pointer block w-full text-sm text-gray-500
            file:mr-4 file:py-2 file:px-4
            file:rounded-md file:border-0
            file:text-sm file:font-semibold
            file:bg-teal-200 file:text-gray-700
            hover:file:bg-teal-300"
          disabled={uploading}
        />
      </div>
      
      {files.length > 0 && (
        <div className="mb-4">
          <h3 className="font-medium mb-2">
            Сонгогдсон: {files.length} файл ({formattedTotalSize} MB)
          </h3>
          <ul className="text-sm text-gray-600 space-y-2">
            {files.map((file, index) => (
              <li key={index} className="flex items-center">
                <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                {file.name} ({(file.size / 1024 / 1024).toFixed(2)} MB)
              </li>
            ))}
          </ul>
          
          {uploadProgress > 0 && (
            <div className="w-full bg-gray-200 rounded-full h-2.5 mt-3">
              <div 
                className="bg-lime-600 h-2.5 rounded-full transition-all duration-300" 
                style={{ width: `${uploadProgress}%` }}
              ></div>
            </div>
          )}
          {uploadProgress > 0 && (
            <div className="text-right text-xs text-gray-500 mt-1">
              {uploadProgress}%
            </div>
          )}
        </div>
      )}
      
      <div className="flex space-x-2">
        <button
          onClick={uploadFiles}
          disabled={files.length === 0 || uploading}
          className="cursor-pointer px-4 py-2 bg-blue-600 text-white font-medium rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-opacity-50 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {uploading ? 'Хуулж байна...' : 'Файл хуулах'}
        </button>
        
        <button
          onClick={resetUploader}
          disabled={files.length === 0 || uploading}
          className="cursor-pointer px-4 py-2 bg-gray-200 text-gray-800 font-medium rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-opacity-50 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          Болих
        </button>
      </div>
      
      {uploadResults.length > 0 && (
        <div className="mt-4">
          <h3 className="font-medium mb-2">Үр дүн:</h3>
          <ul className="text-sm divide-y">
            {uploadResults.map((result, index) => (
              <li key={index} className="py-2">
                <div className="flex items-center">
                  {result.error ? (
                    <span className="text-red-600">
                      {result.name}: {result.error}
                    </span>
                  ) : (
                    <span className="text-green-600">
                      {result.name}: амжилттай хуулагдлаа
                    </span>
                  )}
                </div>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}