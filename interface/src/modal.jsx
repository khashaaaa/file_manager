export const Modal = ({ isOpen, onClose, onConfirm, fileName, isMultiple = false }) => {
  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black opacity-50" onClick={onClose}></div>
      
      <div className="bg-white rounded-lg shadow-xl max-w-md w-full z-10">
        <div className="p-6">
          <div className="flex items-center justify-center w-12 h-12 mx-auto bg-red-100 rounded-full">
            <svg className="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
            </svg>
          </div>
          
          <div className="mt-4 text-center">
            <h3 className="text-lg font-medium text-gray-900">Устгах зөвшөөрөл</h3>
            <p className="mt-2 text-sm text-gray-500">
              {isMultiple ? (
                <>Сонгосон <span className="font-semibold">{fileName}</span> файлуудыг устгах уу?</>
              ) : (
                <>Уг файлыг устгах уу: <span className="font-semibold">{fileName}</span>?</>
              )}
              <br />
              Үйлдэл буцаагдахгүй болохыг анхаарна уу.
            </p>
          </div>
        </div>
        
        <div className="bg-gray-50 px-6 py-4 flex justify-end space-x-3 rounded-b-lg">
          <button
            onClick={onConfirm}
            className="cursor-pointer px-4 py-2 bg-red-500 text-sm text-white rounded hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-opacity-50 transition-colors"
          >
            Устгах
          </button>
          <button
            onClick={onClose}
            className="cursor-pointer px-4 py-2 bg-gray-500 text-sm text-white rounded hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-opacity-50 transition-colors"
          >
            Болих
          </button>
        </div>
      </div>
    </div>
  );
}