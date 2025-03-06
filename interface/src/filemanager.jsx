import React, { useState, useEffect, useCallback } from 'react';
import axios from 'axios';
import { FileUploader } from './fileuploader';
import { FileList } from './filelist';

const API_URL = 'http://localhost:8080';

export const FileManager = () => {
  const [files, setFiles] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);
  const [debugInfo, setDebugInfo] = useState(null);

  const fetchFiles = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    setDebugInfo(null);
    
    try {      
      const response = await axios.get(`${API_URL}/files`, {
        timeout: 10000
      });
      
      if (response.data) {
        if (!response.data.files) {
          setDebugInfo({
            message: 'Response missing "files" property',
            response: response.data
          });
          setFiles([]);
        } else if (!Array.isArray(response.data.files)) {
          setDebugInfo({
            message: '"files" property is not an array',
            type: typeof response.data.files,
            response: response.data
          });
          setFiles([]);
        } else {
          setFiles(response.data.files);
        }
      } else {
        setDebugInfo({
          message: 'Empty response data',
          response: response
        });
        setFiles([]);
      }
    } catch (err) {
      console.error('Error fetching files:', err);
      
      const errorInfo = {
        message: err.message,
        code: err.code,
        response: err.response?.data || null,
        status: err.response?.status || null
      };
      
      setDebugInfo(errorInfo);
      setError(`Failed to load files: ${err.message}`);
      setFiles([]);
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchFiles();
  }, [fetchFiles]);

  const handleFileUploadComplete = () => {
    fetchFiles();
  };

  const handleFileDelete = async (fileId) => {
    try {
      await axios.delete(`${API_URL}/files/${fileId}`);
      setFiles(prevFiles => prevFiles.filter(file => file.id !== fileId));
    } catch (err) {
      console.error('Error deleting file:', err);
      setError(`Failed to delete file: ${err.message}`);
    }
  };

  const handleFileRename = async (fileId, newName) => {
    try {
      await axios.put(`${API_URL}/files/${fileId}`, {
        original_name: newName
      });
      
      setFiles(prevFiles => 
        prevFiles.map(file => 
          file.id === fileId ? { ...file, original_name: newName } : file
        )
      );
    } catch (err) {
      console.error('Error renaming file:', err);
      setError(`Failed to rename file: ${err.message}`);
    }
  };

  return (
    <div className="container mx-auto">
      {error && (
        <div className="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
          <p>{error}</p>
          <button 
            className="mt-2 bg-red-200 hover:bg-red-300 text-red-800 font-semibold py-1 px-3 rounded"
            onClick={() => {
              setError(null);
              fetchFiles();
            }}
          >
            Try again
          </button>
        </div>
      )}
      
      {debugInfo && (
        <div className="bg-yellow-100 border border-yellow-400 text-yellow-800 px-4 py-3 rounded mb-4">
          <details>
            <summary className="font-semibold cursor-pointer">Debug Information (click to expand)</summary>
            <pre className="mt-2 bg-yellow-50 p-2 rounded overflow-auto text-xs">
              {JSON.stringify(debugInfo, null, 2)}
            </pre>
          </details>
        </div>
      )}
      
      <FileUploader 
        apiUrl={API_URL} 
        onUploadComplete={handleFileUploadComplete} 
      />
      
      <FileList 
        files={files} 
        isLoading={isLoading} 
        onDelete={handleFileDelete}
        onRename={handleFileRename}
      />
    </div>
  );
}